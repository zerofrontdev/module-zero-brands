<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\MediaStorage\Model\File\Uploader;
use Magento\MediaStorage\Model\File\UploaderFactory;
use PHPUnit\Framework\TestCase;
use Zero\Brands\Model\Logo;

/**
 * A brand logo is a JPEG, PNG or WebP, by its extension and by its content:
 * no SVG (it can carry script that runs on the store's origin).
 */
class LogoTest extends TestCase
{
    /** @var string[] */
    private array $files = [];
    /** @var string[] what the uploader was told to allow */
    private array $allowed = [];
    /** @var array<int, array{0: string, 1: object, 2: string}> */
    private array $callbacks = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testSvgIsNotAnAllowedExtension(): void
    {
        $logo = $this->logo();

        $this->assertSame('zero/brand/acme.png', $logo->save(['name' => 'acme.png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK]));
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], $this->allowed);
        $this->assertNotContains('svg', $this->allowed);
        $this->assertSame([['zero_brand_logo', $logo, 'validateImage']], $this->callbacks, 'the content is checked too');
    }

    public function testAnSvgUploadIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The logo couldn\'t be saved: Disallowed file type.');

        $this->logo()->save(['name' => 'acme.svg', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK]);
    }

    public function testAFailedUploadIsRefusedBeforeTheUploader(): void
    {
        $factory = $this->createMock(UploaderFactory::class);
        $factory->expects($this->never())->method('create');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The logo didn\'t upload (too large?).');

        (new Logo($factory, $this->createStub(Filesystem::class)))->save(['name' => 'a.png', 'error' => UPLOAD_ERR_INI_SIZE]);
    }

    public function testAPngThatIsReallySvgIsRefused(): void
    {
        $file = $this->file('png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Not a JPEG, PNG or WebP image.');

        $this->logo()->validateImage($file);
    }

    public function testAnotherImageTypeOrNoFileIsRefused(): void
    {
        $gif = $this->file('gif', '');
        $image = imagecreatetruecolor(1, 1);
        imagegif($image, $gif);

        foreach ([$gif, sys_get_temp_dir() . '/zero-brand-logo-missing.png'] as $path) {
            try {
                $this->logo()->validateImage($path);
                $this->fail('accepted ' . basename($path));
            } catch (\InvalidArgumentException $e) {
                $this->assertSame('Not a JPEG, PNG or WebP image.', $e->getMessage());
            }
        }
    }

    public function testRealPngJpegAndWebpPass(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD without WebP');
        }
        $image = imagecreatetruecolor(1, 1);
        $png = $this->file('png', '');
        imagepng($image, $png);
        $jpeg = $this->file('jpg', '');
        imagejpeg($image, $jpeg);
        $webp = $this->file('webp', '');
        imagewebp($image, $webp);

        $checked = [];
        foreach ([$png, $jpeg, $webp] as $path) {
            $this->logo()->validateImage($path);
            $checked[] = pathinfo($path, PATHINFO_EXTENSION);
        }

        $this->assertSame(['png', 'jpg', 'webp'], $checked);
    }

    private function logo(): Logo
    {
        $factory = $this->createStub(UploaderFactory::class);
        $factory->method('create')->willReturnCallback(function (array $args) {
            $name = (string) $args['fileId']['name'];
            $uploader = $this->createStub(Uploader::class);
            $uploader->method('setAllowedExtensions')->willReturnCallback(function (array $extensions) use ($uploader) {
                $this->allowed = $extensions;
                return $uploader;
            });
            $uploader->method('addValidateCallback')->willReturnCallback(function ($name, $object, $method) use ($uploader) {
                $this->callbacks[] = [$name, $object, $method];
                return $uploader;
            });
            // As Magento's uploader: an extension outside the allowed list is refused.
            $uploader->method('save')->willReturnCallback(function () use ($name) {
                if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->allowed, true)) {
                    throw new \Exception('Disallowed file type.');
                }
                return ['file' => $name];
            });
            return $uploader;
        });
        $media = $this->createStub(WriteInterface::class);
        $media->method('getAbsolutePath')->willReturnCallback(static fn ($path) => '/media/' . $path);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($media);

        return new Logo($factory, $filesystem);
    }

    private function file(string $extension, string $content): string
    {
        $path = sys_get_temp_dir() . '/zero-brand-logo-' . bin2hex(random_bytes(4)) . '.' . $extension;
        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }
}
