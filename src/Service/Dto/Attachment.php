<?php
/**
 * Sage plugin (NataPHP).
 *
 * Multi-provider LLM actions (Claude, OpenAI, Gemini) behind one API.
 *
 * @package Sage
 */
namespace Sage\Service\Dto;

use Nata\FilesystemManager\File;
use Sage\Service\Exception\LLMValidationException;

/**
 * Bytes sent to the model alongside the prompt (image, PDF, audio...).
 *
 * Built by LLMService from the `attachments` option, which accepts
 * `Nata\FilesystemManager\File` instances (any store) or Attachment instances
 * (e.g. `Attachment::fromBytes()` for content that is not a file).
 * Attachments are backend-injected only: never build one from untrusted client input
 * without checking it first.
 */
final class Attachment {

/**
 * Constructor.
 *
 * @param string $mime MIME type (normalised to lower case).
 * @param string $data Raw bytes.
 * @param string $name Original name, for logs.
 * @throws \Sage\Service\Exception\LLMValidationException When the data is empty.
 */
    public function __construct(
        public readonly string $mime,
        public readonly string $data,
        public readonly string $name = ''
    ) {
        if ($data === '') {
            throw new LLMValidationException('Attachment is empty.', 0, null, ['name' => $name, 'mime' => $mime]);
        }
    }

/**
 * Build from raw bytes; the MIME type is detected when not given.
 *
 * @param string $data Raw bytes.
 * @param string|null $mime MIME type, or null to detect it.
 * @param string $name Original name.
 * @return self
 */
    public static function fromBytes(string $data, ?string $mime = null, string $name = ''): self {
        if ($mime === null || $mime === '') {
            $mime = self::detectMime($data);
        }
        return new self(self::normaliseMime($mime), $data, $name);
    }

/**
 * Build from a Nata file (local or remote store).
 *
 * @param \Nata\FilesystemManager\File $file File.
 * @return self
 * @throws \Sage\Service\Exception\LLMValidationException When the file cannot be read.
 */
    public static function fromFile(File $file): self {
        $data = $file->read();
        if (!is_string($data) || $data === '') {
            throw new LLMValidationException('Attachment file could not be read.', 0, null, ['name' => (string)$file->name()]);
        }
        $mime = (string)$file->mime();
        return self::fromBytes($data, $mime !== '' ? $mime : null, (string)$file->name());
    }

/**
 * Top-level MIME type check ('image', 'audio'...) or exact match ('application/pdf').
 *
 * @param string $type Top-level type or full MIME type.
 * @return bool
 */
    public function is(string $type): bool {
        if (str_contains($type, '/')) {
            return $this->mime === strtolower($type);
        }
        return str_starts_with($this->mime, strtolower($type) . '/');
    }

/**
 * Base64 of the bytes.
 *
 * @return string
 */
    public function base64(): string {
        return base64_encode($this->data);
    }

/**
 * `data:` URI of the bytes.
 *
 * @return string
 */
    public function dataUri(): string {
        return 'data:' . $this->mime . ';base64,' . $this->base64();
    }

/**
 * Size in bytes.
 *
 * @return int
 */
    public function size(): int {
        return strlen($this->data);
    }

/**
 * Detect the MIME type of raw bytes.
 *
 * @param string $data Raw bytes.
 * @return string
 */
    public static function detectMime(string $data): string {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_buffer($finfo, $data) : false;
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

/**
 * Lower-case the MIME type and fix common aliases.
 *
 * @param string $mime MIME type.
 * @return string
 */
    public static function normaliseMime(string $mime): string {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        return match ($mime) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            default => $mime,
        };
    }

}
