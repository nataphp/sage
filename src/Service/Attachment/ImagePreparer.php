<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Attachment;

use GdImage;
use Sage\Service\Dto\Attachment;

/**
 * Shrinks image attachments before they are sent.
 *
 * Phone photos are several MB and far larger than what vision models look at
 * (Claude downsizes past ~1568 px on the long edge and rejects images over
 * 5 MB), so sending them as-is only costs bandwidth, latency and tokens.
 *
 * JPEG, PNG and WebP whose long edge exceeds the limit are resampled and
 * re-encoded in the same format. JPEGs also get their EXIF orientation applied
 * first, since re-encoding drops the tag and the model would see the photo
 * rotated. Anything else (HEIC, GIF, PDF, audio...) and images already within
 * the limit pass through untouched. Without GD everything passes through.
 */
final class ImagePreparer {

/**
 * Default long-edge limit in pixels.
 */
    public const DEFAULT_MAX_EDGE = 1568;

/**
 * Formats that can be decoded and re-encoded with GD.
 *
 * @var list<string>
 */
    public const RESIZABLE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

/**
 * Constructor.
 *
 * @param int $maxEdge Long-edge limit in pixels; 0 disables resizing.
 * @param int $quality JPEG/WebP quality (1-100).
 */
    public function __construct(
        protected int $maxEdge = self::DEFAULT_MAX_EDGE,
        protected int $quality = 85
    ) {
    }

/**
 * Return the attachment ready to send (possibly the same instance).
 *
 * @param \Sage\Service\Dto\Attachment $attachment Attachment.
 * @return \Sage\Service\Dto\Attachment
 */
    public function prepare(Attachment $attachment): Attachment {
        if ($this->maxEdge <= 0 || !in_array($attachment->mime, self::RESIZABLE_MIMES, true) || !function_exists('imagecreatefromstring')) {
            return $attachment;
        }

        $dimensions = @getimagesizefromstring($attachment->data);
        if ($dimensions === false) {
            return $attachment;
        }
        [$width, $height] = $dimensions;
        $orientation = $attachment->mime === 'image/jpeg' ? $this->_exifOrientation($attachment->data) : 1;
        if (max($width, $height) <= $this->maxEdge && $orientation === 1) {
            return $attachment;
        }

        $image = @imagecreatefromstring($attachment->data);
        if (!$image instanceof GdImage) {
            return $attachment;
        }
        $image = $this->_orient($image, $orientation);
        $image = $this->_fit($image);

        $encoded = $this->_encode($image, $attachment->mime);
        if ($encoded === null) {
            return $attachment;
        }
        return new Attachment($attachment->mime, $encoded, $attachment->name);
    }

/**
 * EXIF Orientation tag of a JPEG (1 when absent or unreadable).
 *
 * @param string $data JPEG bytes.
 * @return int 1 to 8.
 */
    protected function _exifOrientation(string $data): int {
        if (!function_exists('exif_read_data')) {
            return 1;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($data));
        $orientation = is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1;
        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

/**
 * Rotate/flip the image so it displays upright without the EXIF tag.
 *
 * @param \GdImage $image Decoded image.
 * @param int $orientation EXIF orientation (1-8).
 * @return \GdImage
 */
    protected function _orient(GdImage $image, int $orientation): GdImage {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) {
            3, 4 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        return $rotated instanceof GdImage ? $rotated : $image;
    }

/**
 * Resample so the long edge is at most the limit, keeping transparency.
 *
 * @param \GdImage $image Image.
 * @return \GdImage
 */
    protected function _fit(GdImage $image): GdImage {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = $this->maxEdge / max($width, $height);
        if ($scale >= 1) {
            return $image;
        }

        $targetWidth = max(1, (int)round($width * $scale));
        $targetHeight = max(1, (int)round($height * $scale));
        $fitted = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($fitted, false);
        imagesavealpha($fitted, true);
        imagecopyresampled($fitted, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        return $fitted;
    }

/**
 * Encode the image in the given format.
 *
 * @param \GdImage $image Image.
 * @param string $mime Target MIME type (one of RESIZABLE_MIMES).
 * @return string|null Bytes, or null when encoding failed.
 */
    protected function _encode(GdImage $image, string $mime): ?string {
        ob_start();
        $encoded = match ($mime) {
            'image/png' => imagepng($image, null, 6),
            'image/webp' => function_exists('imagewebp') && imagewebp($image, null, $this->quality),
            default => imagejpeg($image, null, $this->quality),
        };
        $bytes = (string)ob_get_clean();
        return $encoded && $bytes !== '' ? $bytes : null;
    }

}
