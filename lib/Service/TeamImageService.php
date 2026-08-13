<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCA\TeamHub\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
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
 * **Two sources, one URL (v4.6.25).** On NC 34+ the team picture lives in
 * Nextcloud Teams' own avatar storage, not here — see DESIGN §2.68. Circles
 * serves it from an OCS route that an `<img src>` cannot call (OCS needs a
 * header the tag can't send) and that answers 404 when a circle has no
 * avatar, so the frontend used to probe every team over XHR and the browser
 * logged a failed request for each miss. This service now reads that storage
 * directly through the public {@see IAppDataFactory}, so a single
 * TeamHub URL serves whichever source exists and the frontend never has to
 * ask a question whose answer is usually "no".
 *
 * Reading is all that happens here. Writes still go through Circles' OCS
 * routes from the browser, because setting an avatar also emits a CircleEdit
 * federated event that only Circles can raise.
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

    /**
     * Where Circles keeps per-circle avatars, mirrored from its own
     * AvatarService: appdata_<instanceid>/circles/circle-avatar/{circleId}/
     * holding one file whose name starts with `circle-avatar` (`.jpg` or
     * `.png`, decided by the uploaded image's type).
     *
     * This is Circles' internal layout, not a documented contract. Every
     * lookup below fails soft: if the shape ever changes, teams fall back to
     * the legacy image and then to the placeholder icon — nothing throws.
     */
    private const CIRCLES_APP_ID        = 'circles';
    private const CIRCLES_AVATAR_FOLDER = 'circle-avatar';
    private const CIRCLES_AVATAR_PREFIX = 'circle-avatar';

    /** Accepted input MIME types */
    private const ALLOWED_INPUT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /**
     * Memoised `circle-avatar` folder handle. Team lists ask about every team
     * in one request, so resolving Circles' app data once per request keeps
     * that to a single lookup per team instead of three.
     */
    private ?ISimpleFolder $circlesAvatarFolder = null;
    private bool $circlesAvatarFolderResolved   = false;

    public function __construct(
        private IAppData        $appData,
        private IAppDataFactory $appDataFactory,
        private IURLGenerator   $urlGenerator,
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

        $this->assertSafeTeamId($teamId);
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
        $this->assertSafeTeamId($teamId);
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
            $this->assertSafeTeamId($teamId);
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
            $this->assertSafeTeamId($teamId);
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
     * Return true if this team has a Nextcloud Teams (Circles) avatar.
     */
    public function hasNcAvatar(string $teamId): bool {
        return $this->getNcAvatarFile($teamId) !== null;
    }

    /**
     * Which storage the team's picture comes from: `nc` for the Nextcloud
     * Teams avatar, `legacy` for TeamHub's own app-data copy, or null when
     * the team has no picture at all.
     *
     * The Teams avatar wins when both exist — DESIGN §2.68 makes it the
     * source of truth on NC 34+. `legacy` is what the frontend uses to decide
     * whether a team still needs the one-way migration into Teams.
     */
    public function getImageSource(string $teamId): ?string {
        if ($this->hasNcAvatar($teamId)) {
            return 'nc';
        }
        return $this->hasImage($teamId) ? 'legacy' : null;
    }

    /**
     * Return the public URL for a team image, or null if none exists.
     * The URL points to GET /apps/teamhub/api/v1/teams/{teamId}/image.
     *
     * The URL is the same whichever storage holds the bytes, so display sites
     * render `image_url` and never care which source answered.
     *
     * **A non-null URL is a promise that the route will return an image.**
     * Callers whose payload can reach a non-member must additionally withhold
     * it — see TeamService::browseAllTeams() and ::getTeamPreview(). An
     * `<img src>` pointing at a route that answers 403 puts a failed request
     * in every visitor's console, which is the noise this design removes.
     */
    public function getImageUrl(string $teamId): ?string {
        if ($this->getImageSource($teamId) === null) {
            return null;
        }
        return $this->urlGenerator->linkToRoute('teamhub.teamImage.serve', [
            'teamId' => $teamId,
        ]);
    }

    /**
     * URL and storage in one lookup, for callers that need both.
     *
     * Team lists want the URL to render and the source to decide whether the
     * team still needs migrating into Teams. Asking for them separately walks
     * the app-data folders twice per team, which on a large instance is the
     * difference that matters.
     *
     * @return array{url: ?string, source: ?string}
     */
    public function describeImage(string $teamId): array {
        $source = $this->getImageSource($teamId);
        if ($source === null) {
            return ['url' => null, 'source' => null];
        }
        return [
            'url' => $this->urlGenerator->linkToRoute('teamhub.teamImage.serve', [
                'teamId' => $teamId,
            ]),
            'source' => $source,
        ];
    }

    /**
     * The bytes to serve for this team, with the MIME type they are actually
     * encoded as. Teams avatar first, legacy TeamHub image second, null when
     * the team has neither.
     *
     * The MIME type is read off the stored file rather than assumed: TeamHub's
     * own images are always JPEG, but Circles accepts PNG too.
     *
     * @return array{data: string, mime: string}|null
     */
    public function getServableImage(string $teamId): ?array {
        $ncFile = $this->getNcAvatarFile($teamId);
        if ($ncFile !== null) {
            try {
                return [
                    'data' => $ncFile->getContent(),
                    'mime' => $ncFile->getMimeType() ?: self::MIME_TYPE,
                ];
            } catch (\Throwable $e) {
                // Fall through to the legacy copy — a team mid-migration has
                // both, and an unreadable avatar should not blank the picture.
                $this->logger->debug('[TeamHub][TeamImageService] Teams avatar unreadable, falling back', [
                    'teamId' => $teamId,
                    'app'    => Application::APP_ID,
                ]);
            }
        }

        $legacy = $this->getImageData($teamId);
        if ($legacy === null) {
            return null;
        }
        return ['data' => $legacy, 'mime' => self::MIME_TYPE];
    }

    /**
     * The stored Nextcloud Teams avatar for a circle, or null when it has
     * none. Mirrors Circles' own AvatarService::getAvatar() lookup.
     *
     * No membership check happens here — this is storage access, and the
     * authorisation gate lives at the controller, where Circles puts its own.
     */
    private function getNcAvatarFile(string $teamId): ?ISimpleFile {
        try {
            $this->assertSafeTeamId($teamId);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        $root = $this->getCirclesAvatarFolder();
        if ($root === null || !$root->fileExists($teamId)) {
            return null;
        }

        try {
            foreach ($root->getFolder($teamId)->getDirectoryListing() as $file) {
                if (str_starts_with($file->getName(), self::CIRCLES_AVATAR_PREFIX)) {
                    return $file;
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /**
     * Circles' `circle-avatar` app-data folder, or null when Circles is not
     * installed or has never stored an avatar (it creates the folder lazily on
     * first upload, so its absence is the ordinary state, not a fault).
     *
     * Resolved at most once per request, including the null answer.
     */
    private function getCirclesAvatarFolder(): ?ISimpleFolder {
        if ($this->circlesAvatarFolderResolved) {
            return $this->circlesAvatarFolder;
        }
        $this->circlesAvatarFolderResolved = true;

        try {
            $this->circlesAvatarFolder = $this->appDataFactory
                ->get(self::CIRCLES_APP_ID)
                ->getFolder(self::CIRCLES_AVATAR_FOLDER);
        } catch (\Throwable $e) {
            $this->circlesAvatarFolder = null;
        }

        return $this->circlesAvatarFolder;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Reject any teamId that could escape the team-images folder when used as
     * a filename. Circle IDs are short alphanumeric tokens; a value containing
     * a path separator, a null byte, or a parent reference is never legitimate
     * and must not reach ISimpleFolder::getFile()/newFile().
     */
    private function assertSafeTeamId(string $teamId): void {
        if ($teamId === ''
            || str_contains($teamId, '/')
            || str_contains($teamId, '\\')
            || str_contains($teamId, "\0")
            || str_contains($teamId, '..')) {
            throw new \InvalidArgumentException('Invalid team identifier.');
        }
    }

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
