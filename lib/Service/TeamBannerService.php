<?php
declare(strict_types=1);

namespace OCA\TeamHub\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Stores and retrieves the per-team banner — the wide picture that sits
 * between the tab bar and the team's content on every tab of the team.
 *
 * Storage layout:
 *   appdata_<instanceid>/teamhub/team-banners/{teamId}.jpg
 *
 * This is deliberately a sibling of {@see TeamImageService} rather than a
 * second mode of it, for three reasons that all cut the same way:
 *
 *   - **Different storage question.** A team *picture* has two sources since
 *     v4.6.25 (Nextcloud Teams' own avatar wins over TeamHub's app-data copy —
 *     DESIGN §2.95). A banner has exactly one: Circles has no concept of it,
 *     so none of that resolution machinery applies here and folding it in
 *     would mean carrying it for nothing.
 *   - **Different geometry.** The picture is a ≤200×200 square shown at 24–48
 *     px; the banner is a wide photograph whose job is to look like a
 *     photograph behind overlaid text. Capping it at the picture's dimensions
 *     would make it unusable, and giving TeamImageService two dimension sets
 *     would make its one constant a lie.
 *   - **Different failure.** A missing picture falls back to initials. A
 *     missing banner means the banner strip is not rendered at all.
 *
 * The *shape* is copied on purpose: same app-data access, same safe-id guard,
 * same re-encode-to-JPEG discipline, same "a non-null URL is a promise the
 * route will answer" contract. If one of them learns something (a CDN, a
 * different output format), the other is the first place to look.
 *
 * **Cache busting rides the URL.** The serve route sends a day of
 * `Cache-Control`, so a replaced banner would otherwise keep showing the old
 * picture for every member who already loaded it. {@see getBannerUrl()}
 * therefore carries the stored file's modification time as `v`, which changes
 * the URL the moment the bytes change — the browser treats it as a new
 * resource and nobody has to guess a TTL.
 */
class TeamBannerService {

    public const FOLDER    = 'team-banners';
    public const MIME_TYPE = 'image/jpeg';
    public const FILE_EXT  = '.jpg';

    /**
     * Scaled to fit inside this box, aspect ratio preserved and never
     * upscaled. Wide enough that a full-width banner on a large screen is
     * still sharp; small enough that the stored JPEG stays a few hundred
     * kilobytes. The frontend crops to the strip with `object-fit: cover`,
     * which is why the height bound is generous rather than banner-shaped —
     * a portrait photograph is cropped at render time, not here, so the part
     * the team chose to show is not thrown away at upload.
     */
    public const MAX_WIDTH  = 1920;
    public const MAX_HEIGHT = 1080;

    /** Upload size cap in bytes, enforced by the controller as well. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /**
     * Refuse an image above this many pixels before decoding it. GD allocates
     * roughly four bytes per pixel, so a 60-megapixel upload is ~240 MB of
     * memory and takes php-fpm down rather than answering 400. The byte cap
     * above does not catch it: a highly compressible 12000×8000 photograph
     * fits in 8 MB comfortably.
     */
    public const MAX_PIXELS = 50_000_000;

    /** Accepted input MIME types — the same set the team picture accepts. */
    private const ALLOWED_INPUT_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private IAppData        $appData,
        private IURLGenerator   $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Process and store a team banner from raw uploaded bytes.
     *
     * @param string $teamId   The team's circle ID
     * @param string $rawData  Raw binary data of the uploaded file
     * @param string $mimeType MIME type reported by the upload
     *
     * @throws \InvalidArgumentException on bad input (wrong type, too large, corrupt)
     * @throws \RuntimeException         on storage failure
     */
    public function storeBanner(string $teamId, string $rawData, string $mimeType): void {

        $this->assertSafeTeamId($teamId);
        $this->validateMime($mimeType);
        $this->assertDecodableSize($rawData);

        $source = @imagecreatefromstring($rawData);
        if ($source === false) {
            throw new \InvalidArgumentException('Could not decode image data. The file may be corrupt or unsupported.');
        }

        try {
            $scaled = $this->scaleToFit($source);
        } finally {
            imagedestroy($source);
        }

        try {
            $jpeg = $this->encodeJpeg($scaled);
        } finally {
            imagedestroy($scaled);
        }

        $this->writeToAppData($teamId, $jpeg);
    }

    /**
     * Remove a team banner. Silent if none is stored.
     */
    public function removeBanner(string $teamId): void {
        $this->assertSafeTeamId($teamId);
        try {
            $this->getOrCreateFolder()->getFile($teamId . self::FILE_EXT)->delete();
        } catch (NotFoundException $e) {
            // Already gone — nothing to do.
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to remove banner: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Raw JPEG bytes of the stored banner, or null when the team has none.
     */
    public function getBannerData(string $teamId): ?string {
        try {
            $this->assertSafeTeamId($teamId);
            return $this->getOrCreateFolder()->getFile($teamId . self::FILE_EXT)->getContent();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether this team has a banner.
     */
    public function hasBanner(string $teamId): bool {
        return $this->bannerVersion($teamId) !== null;
    }

    /**
     * The public URL of the team's banner, or null when it has none.
     *
     * **A non-null URL is a promise that the route will return an image** —
     * the same contract {@see TeamImageService::getImageUrl()} documents, and
     * for the same reason: an `<img src>` pointing at a 403 or a 404 puts a
     * failed request in every member's console. Callers whose payload can
     * reach a non-member must withhold it.
     */
    public function getBannerUrl(string $teamId): ?string {
        $version = $this->bannerVersion($teamId);
        if ($version === null) {
            return null;
        }

        return $this->urlGenerator->linkToRoute('teamhub.teamBanner.serve', [
            'teamId' => $teamId,
            // Cache buster — see the class docblock.
            'v'      => (string)$version,
        ]);
    }

    /**
     * The bytes to serve, with the MIME type they are encoded as. Always
     * JPEG here: everything is re-encoded on the way in.
     *
     * @return array{data: string, mime: string}|null
     */
    public function getServableBanner(string $teamId): ?array {
        $data = $this->getBannerData($teamId);
        if ($data === null) {
            return null;
        }
        return ['data' => $data, 'mime' => self::MIME_TYPE];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * The stored file's modification time, which doubles as the banner's
     * version, or null when the team has no banner.
     */
    private function bannerVersion(string $teamId): ?int {
        try {
            $this->assertSafeTeamId($teamId);
            return $this->getOrCreateFolder()->getFile($teamId . self::FILE_EXT)->getMTime();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reject any teamId that could escape the banner folder when used as a
     * filename. Same guard as TeamImageService — a circle ID is a short
     * alphanumeric token, and a value with a path separator, a null byte or a
     * parent reference is never legitimate.
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
        // Strip charset/boundary suffixes (e.g. "image/jpeg; charset=binary").
        $base = strtolower(trim(explode(';', $mimeType)[0]));
        if (!in_array($base, self::ALLOWED_INPUT_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported image type: ' . $base . '. Accepted: ' . implode(', ', self::ALLOWED_INPUT_TYPES)
            );
        }
    }

    /**
     * Refuse an image whose pixel count would exhaust memory in GD, reading
     * only the header. A failure to read the header is not fatal here —
     * imagecreatefromstring() is the real gate and answers 400 on garbage.
     */
    private function assertDecodableSize(string $rawData): void {
        $info = @getimagesizefromstring($rawData);
        if (!is_array($info) || !isset($info[0], $info[1])) {
            return;
        }
        $pixels = (int)$info[0] * (int)$info[1];
        if ($pixels > self::MAX_PIXELS) {
            throw new \InvalidArgumentException(
                'Image resolution is too high. Maximum is ' . (int)(self::MAX_PIXELS / 1_000_000) . ' megapixels.'
            );
        }
    }

    /**
     * Scale to fit inside MAX_WIDTH × MAX_HEIGHT, preserving aspect ratio.
     * An image already inside the box is copied unchanged, so the caller can
     * destroy source and result independently.
     */
    private function scaleToFit(\GdImage $src): \GdImage {
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $scale = min(1.0, self::MAX_WIDTH / $srcW, self::MAX_HEIGHT / $srcH);
        $dstW  = max(1, (int)round($srcW * $scale));
        $dstH  = max(1, (int)round($srcH * $scale));

        $dst = imagecreatetruecolor($dstW, $dstH);
        // JPEG has no alpha channel: flatten onto white rather than letting a
        // transparent PNG come out with black where it was see-through.
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $dst;
    }

    private function encodeJpeg(\GdImage $image): string {
        ob_start();
        // 82 rather than the picture's 85: a banner is an order of magnitude
        // more pixels and the difference is not visible behind the overlay.
        imagejpeg($image, null, 82);
        $data = ob_get_clean();
        if ($data === false || $data === '') {
            throw new \RuntimeException('Failed to encode banner as JPEG');
        }
        return $data;
    }

    private function writeToAppData(string $teamId, string $data): void {
        $folder   = $this->getOrCreateFolder();
        $filename = $teamId . self::FILE_EXT;

        try {
            $folder->getFile($filename)->putContent($data);
        } catch (NotFoundException $e) {
            $folder->newFile($filename, $data);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to write banner to storage: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getOrCreateFolder(): ISimpleFolder {
        try {
            return $this->appData->getFolder(self::FOLDER);
        } catch (NotFoundException $e) {
            return $this->appData->newFolder(self::FOLDER);
        }
    }
}
