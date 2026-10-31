<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Brands\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\MediaStorage\Model\File\UploaderFactory;

/**
 * A brand's logo upload into pub/media/zero/brand: JPEG, PNG or WebP. No SVG
 * (it can carry <script> that runs on the store's origin from pub/media), as
 * for banners; the file's content is checked too, not only its extension.
 * Logos stored before (SVG included) keep showing.
 */
class Logo
{
    private const DIR = 'zero/brand';

    public function __construct(
        private readonly UploaderFactory $uploaderFactory,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @return string The logo's path under pub/media
     * @throws LocalizedException
     */
    public function save(array $upload): string
    {
        if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK) {
            throw new LocalizedException(__('The logo didn\'t upload (too large?).'));
        }
        try {
            $uploader = $this->uploaderFactory->create(['fileId' => $upload]);
            $uploader->setAllowedExtensions(['jpg', 'jpeg', 'png', 'webp']);
            $uploader->addValidateCallback('zero_brand_logo', $this, 'validateImage');
            $uploader->setAllowRenameFiles(true);
            $uploader->setFilesDispersion(false);
            $result = $uploader->save($this->filesystem->getDirectoryWrite(DirectoryList::MEDIA)->getAbsolutePath(self::DIR));
        } catch (\Throwable $e) {
            throw new LocalizedException(__('The logo couldn\'t be saved: %1', $e->getMessage()));
        }

        return self::DIR . '/' . $result['file'];
    }

    /**
     * The uploader's check of the uploaded file. Not the image adapter's
     * validateUploadFile(): GD can't open WebP there, so it refuses WebP logos.
     *
     * @throws \InvalidArgumentException
     */
    public function validateImage(string $path): void
    {
        $info = is_file($path) ? getimagesize($path) : false;
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new \InvalidArgumentException('Not a JPEG, PNG or WebP image.');
        }
    }
}
