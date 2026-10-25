<?php
/**
 * Sage plugin (NataPHP) tests.
 *
 * @package Sage
 */
namespace Sage\Test\TestCase\Service;

use PHPUnit\Framework\TestCase;
use Sage\Service\Attachment\ImagePreparer;
use Sage\Service\Dto\Attachment;

/**
 * Shrinking image attachments.
 */
class ImagePreparerTest extends TestCase {

/**
 * Skip without GD.
 *
 * @return void
 */
    protected function setUp(): void {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is not available.');
        }
    }

/**
 * Encode a blank image.
 *
 * @param int $width Width.
 * @param int $height Height.
 * @param string $format 'jpeg' or 'png'.
 * @return string
 */
    protected function _image(int $width, int $height, string $format): string {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);
        return (string)ob_get_clean();
    }

/**
 * Large images are scaled to the long-edge limit, keeping the format and ratio.
 *
 * @return void
 */
    public function testLargeImagesAreScaled(): void {
        $prepared = (new ImagePreparer(400))->prepare(Attachment::fromBytes($this->_image(1600, 800, 'jpeg'), null, 'photo.jpg'));

        $this->assertSame('image/jpeg', $prepared->mime);
        $this->assertSame('photo.jpg', $prepared->name);
        [$width, $height] = getimagesizefromstring($prepared->data);
        $this->assertSame([400, 200], [$width, $height]);

        $png = (new ImagePreparer(100))->prepare(Attachment::fromBytes($this->_image(300, 600, 'png')));
        $this->assertSame('image/png', $png->mime);
        $this->assertSame([50, 100], array_slice(getimagesizefromstring($png->data), 0, 2));
    }

/**
 * Small images, other types and a disabled limit pass through unchanged.
 *
 * @return void
 */
    public function testPassThrough(): void {
        $small = Attachment::fromBytes($this->_image(200, 100, 'jpeg'));
        $this->assertSame($small, (new ImagePreparer(400))->prepare($small));

        $pdf = new Attachment('application/pdf', '%PDF-1.4');
        $this->assertSame($pdf, (new ImagePreparer(400))->prepare($pdf));

        $large = Attachment::fromBytes($this->_image(1600, 800, 'jpeg'));
        $this->assertSame($large, (new ImagePreparer(0))->prepare($large));
    }

}
