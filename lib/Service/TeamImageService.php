<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Stores and retrieves per-team avatar images in NC app data.
 *
 * Storage layout:
 *   appdata_<instanceid>/teamhub/team-images/{teamId}.jpg
 *
 * All uploaded images are re-encoded as JPEG at ≤200×200 px before saving,
 * so the stored file is always a predictable, small JPEG regardless of
 * what the user uploaded.
 *
 * Extensibility notes:
 *   - To support other image types as output, change the imagejpeg() call
 *     and the MIME constant.
 *   - To add CDN / object-storage support, replace the IAppData calls with
 *     your preferred storage backend — the public API of this service does
 *     not change.
 */
class TeamImageService {

    public const FOLDER         = 'team-images';
    public const MAX_DIMENSION  = 200;
    public const MIME_TYPE      = 'image/jpeg';
    public const FILE_EXT       = '.jpg';

    /** Accepted input MIME types */
    private const ALLOWED_INPUT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private IAppData      $appData,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process and store a team image from raw uploaded bytes.
     *
     * @param string $teamId   The team's circle ID
     * @param string $rawData  Raw binary data of the uploaded file
     * @param string $mimeType MIME type reported by the upload
     *
     * @throws \InvalidArgumentException  on bad input (wrong type, too small, corrupt)
     * @throws \RuntimeException          on storage failure
     */
    public function storeImage(string $teamId, string $rawData, string $mimeType): void {

        $this->validateMime($mimeType);

        $gdImage = $this->loadGdImage($rawData, $mimeType);

        $resized = $this->resizeImage($gdImage);
        imagedestroy($gdImage);

        $jpegData = $this->encodeJpeg($resized);
        imagedestroy($resized);

        $this->writeToAppData($teamId, $jpegData);

    }

    /**
     * Remove a team image. Silent if no image exists.
     */
    public function removeImage(string $teamId): void {
        try {
            $folder = $this->getOrCreateFolder();
            $file   = $folder->getFile($teamId . self::FILE_EXT);
            $file->delete();
        } catch (NotFoundException $e) {
            // Already gone — nothing to do
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to remove image: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Return the raw JPEG bytes for a team image.
     *
     * @return string|null  Raw JPEG bytes, or null if no image is stored
     */
    public function getImageData(string $teamId): ?string {
        try {
            $folder = $this->getOrCreateFolder();
            $file   = $folder->getFile($teamId . self::FILE_EXT);
            $data   = $file->getContent();
            return $data;
        } catch (NotFoundException $e) {
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Return true if a team image exists.
     */
    public function hasImage(string $teamId): bool {
        try {
            $folder = $this->getOrCreateFolder();
            $folder->getFile($teamId . self::FILE_EXT);
            return true;
        } catch (NotFoundException $e) {
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return the public URL for a team image, or null if none exists.
     * The URL points to GET /apps/teamhub/api/v1/teams/{teamId}/image.
     */
    public function getImageUrl(string $teamId): ?string {
        if (!$this->hasImage($teamId)) {
            return null;
        }
        return $this->urlGenerator->linkToRoute('teamhub.teamImage.serve', [
            'teamId' => $teamId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateMime(string $mimeType): void {
        // Strip charset/boundary suffixes (e.g. "image/jpeg; charset=binary")
        $base = explode(';', $mimeType)[0];
        $base = strtolower(trim($base));
        if (!in_array($base, self::ALLOWED_INPUT_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported image type: ' . $base . '. Accepted: ' . implode(', ', self::ALLOWED_INPUT_TYPES)
            );
        }
    }

    /**
     * Load raw bytes into a GD resource.
     *
     * @throws \InvalidArgumentException if the data is not a valid image
     */
    private function loadGdImage(string $rawData, string $mimeType): \GdImage {
        $image = @imagecreatefromstring($rawData);
        if ($image === false) {
            throw new \InvalidArgumentException('Could not decode image data. The file may be corrupt or unsupported.');
        }
        return $image;
    }

    /**
     * Scale the image to fit within MAX_DIMENSION × MAX_DIMENSION, preserving
     * aspect ratio. If already within bounds, returns the original unchanged.
     */
    private function resizeImage(\GdImage $src): \GdImage {
        $origW = imagesx($src);
        $origH = imagesy($src);

        if ($origW <= self::MAX_DIMENSION && $origH <= self::MAX_DIMENSION) {
            // Already within limits — clone to a new true-colour image so the
            // caller can call imagedestroy() on both independently.
            $dst = imagecreatetruecolor($origW, $origH);
            // Preserve transparency for PNGs that were decoded as truecolour
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagecopy($dst, $src, 0, 0, 0, 0, $origW, $origH);
            return $dst;
        }

        // Scale to fit
        $scale  = min(self::MAX_DIMENSION / $origW, self::MAX_DIMENSION / $origH);
        $newW   = (int)round($origW * $scale);
        $newH   = (int)round($origH * $scale);

        $dst = imagecreatetruecolor($newW, $newH);
        // White background (JPEG has no transparency)
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

        return $dst;
    }

    /**
     * Encode a GD image as JPEG and return the raw bytes.
     */
    private function encodeJpeg(\GdImage $image): string {
        ob_start();
        imagejpeg($image, null, 85); // quality 85 — good balance of size vs quality
        $data = ob_get_clean();
        if ($data === false || $data === '') {
            throw new \RuntimeException('Failed to encode image as JPEG');
        }
        return $data;
    }

    /**
     * Write raw bytes to the app data folder, creating it if needed.
     */
    private function writeToAppData(string $teamId, string $data): void {
        $folder   = $this->getOrCreateFolder();
        $filename = $teamId . self::FILE_EXT;

        try {
            // Overwrite if exists
            $file = $folder->getFile($filename);
            $file->putContent($data);
        } catch (NotFoundException $e) {
            // Create new
            $folder->newFile($filename, $data);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to write image to storage: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Return (or create) the ISimpleFolder for team images.
     */
    private function getOrCreateFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}
