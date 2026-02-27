<?php
declare(strict_types=1);

/**
 * Self-contained ImageService
 *
 * Purpose:
 *  - Given a request URI (path portion) and a document root, produce a resized/cached image.
 *  - Does not access globals. All inputs are injected.
 *
 * Usage (high level):
 *  $svc = new ImageService(documentRoot: $_SERVER['DOCUMENT_ROOT']);
 *  $svc->setCacheRoot('/cache/images'); // optional (absolute or path relative to document root)
 *  $svc->setPermissions(dirPerm: 0775, filePerm: 0644);
 *  $response = $svc->processRequest(requestPath: '/files/foo.png', size: 200, min: false);
 *  // $response = ['status' => 200, 'headers' => [...], 'body_stream' => resource];
 *
 * Note: caller must handle sending headers/body and mapping exceptions to HTTP codes.
 */
class ImageService
{
    // Configuration
    protected string $documentRoot;         // absolute, no trailing slash
    protected string $cacheRoot;            // absolute path or path relative to documentRoot (no trailing slash)
    protected ?int $dirPerm = 0777;
    protected ?int $filePerm = 0644;
    protected ?string $dirOwner = null;
    protected ?string $dirGroup = null;
    protected array $uriRewrites = [];
    protected array $uriAppends = [];

    // Internals
    protected array $allowedImageMimes = [
        'image/jpeg' => 'jpeg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
    ];

    public function __construct(string $documentRoot, string $cacheRoot = '')
    {
        $doc = rtrim($documentRoot, DIRECTORY_SEPARATOR);
        if ($doc === '' || !is_dir($doc)) {
            throw new InvalidArgumentException('Invalid document root');
        }

        $this->documentRoot = $doc;
        // default cache root under site root if not provided
        $this->cacheRoot = $cacheRoot === '' ? $doc . DIRECTORY_SEPARATOR . 'cache' : $this->normalizeCacheRoot($cacheRoot);
    }

    /**
     * Set cache root; accepts absolute or path relative to documentRoot.
     */
    public function setCacheRoot(string $cacheRoot): void
    {
        $this->cacheRoot = $this->normalizeCacheRoot($cacheRoot);
    }

    protected function normalizeCacheRoot(string $cacheRoot): string
    {
        if ($cacheRoot === '') {
            return $this->documentRoot . DIRECTORY_SEPARATOR . 'cache';
        }
        // If absolute
        if ($cacheRoot[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:\\\\#', $cacheRoot) === 1) {
            return rtrim($cacheRoot, DIRECTORY_SEPARATOR);
        }
        // relative to document root
        return $this->documentRoot . DIRECTORY_SEPARATOR . ltrim($cacheRoot, DIRECTORY_SEPARATOR);
    }

    public function setPermissions(?int $dirPerm = null, ?int $filePerm = null, ?string $dirOwner = null, ?string $dirGroup = null): void
    {
        $this->dirPerm  = $dirPerm  ?? $this->dirPerm;
        $this->filePerm = $filePerm ?? $this->filePerm;
        $this->dirOwner = $dirOwner;
        $this->dirGroup = $dirGroup;
    }

    public function setUriRewrites(array $rewrites): void
    {
        $this->uriRewrites = $rewrites;
    }

    public function setUriAppends(array $appends): void
    {
        $this->uriAppends = $appends;
    }

    /**
     * Main entry point.
     *
     * @param string $requestPath  The path portion of the request (e.g. '/files/foo.png' — no query string required)
     * @param int    $size         Requested size (s)
     * @param bool   $min          If true, "min" resizing behavior; otherwise "max"
     *
     * @return array  ['status' => int, 'headers' => array, 'body_stream' => resource]
     *
     * @throws RuntimeException on errors
     */
    public function processRequest(string $requestPath, int $size = 0, bool $min = false): array
    {
        // Normalize request path (remove query string if accidentally included)
        $path = $this->stripQuery($requestPath);
        $path = $this->applyUriTransformations($path);

        // Resolve file and secure it
        $file = urldecode($this->resolveFilesystemPath($path));

        #echo "<pre>"; var_dump(urldecode($file)); echo "</pre>"; exit;

        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Base image not found or unreadable');
        }

        // If no size given, simply return base image
        if ($size <= 0) {
            return $this->buildResponseFromFile($file);
        }

        // Validate type via finfo
        $mime = $this->detectMime($file);
        if (!isset($this->allowedImageMimes[$mime])) {
            throw new RuntimeException('Unsupported image type');
        }
        $type = $this->allowedImageMimes[$mime];

        // Build cache directory and derived name
        $cacheDir = $this->cacheRoot . DIRECTORY_SEPARATOR . ltrim(pathinfo($path, PATHINFO_DIRNAME), DIRECTORY_SEPARATOR);
        $this->ensureDirectoryExists($cacheDir);

        // A robust cache key (path + mtime + size + min + file size)
        $baseKey = $file . '|' . filemtime($file) . '|' . filesize($file) . '|' . $size . '|' . ($min ? 'min' : 'max');
        $derivedName = hash('sha256', $baseKey) . '.' . $this->extensionForType($type);
        $derivedPath = $cacheDir . DIRECTORY_SEPARATOR . $derivedName;

        // If cached, return
        if (is_file($derivedPath) && is_readable($derivedPath)) {
            return $this->buildResponseFromFile($derivedPath);
        }

        // Generate, using an exclusive lock to avoid concurrent writes
        $this->generateDerivedImageWithLock($file, $derivedPath, $size, $type, $min);

        // Final sanity check
        if (!is_file($derivedPath) || !is_readable($derivedPath)) {
            throw new RuntimeException('Derived image generation failed');
        }

        return $this->buildResponseFromFile($derivedPath);
    }

    /* -------------------------
     * Helper implementations
     * ------------------------- */

    protected function stripQuery(string $uri): string
    {
        $pos = strpos($uri, '?');
        return $pos === false ? $uri : substr($uri, 0, $pos);
    }

    protected function applyUriTransformations(string $uri): string
    {
        foreach ($this->uriRewrites as $pattern => $replace) {
            $uri = preg_replace('#' . $pattern . '#', $replace, $uri);
        }
        foreach ($this->uriAppends as $a) {
            $uri .= $a;
        }
        return $uri;
    }

    /**
     * Resolve a requested path to a filesystem path safely (prevents traversal).
     */
    protected function resolveFilesystemPath(string $requestPath): string
    {
        // Decode URL-encoded path safely (for filesystem use)
        $decoded = rawurldecode($requestPath);

        // Remove leading slash
        $clean = '/' . ltrim($decoded, '/');

        // Prevent null byte injection
        $clean = str_replace("\0", '', $clean);

        // Canonicalize by resolving to absolute and verifying prefix
        $candidate = realpath($this->documentRoot . $clean);
        if ($candidate === false) {
            // realpath fails for non-existing files; build candidate without realpath but validate
            $candidate = $this->documentRoot . $clean;
        }

        // Ensure candidate is inside document root (simple prefix check after realpath where possible)
        $docReal = realpath($this->documentRoot) ?: $this->documentRoot;
        if (strpos($candidate, $docReal) !== 0) {
            throw new RuntimeException('Path traversal attempt or invalid path');
        }

        return $candidate;
    }

    protected function detectMime(string $file): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        try {
            $mime = finfo_file($finfo, $file);
        } finally {
            finfo_close($finfo);
        }
        return $mime ?: 'application/octet-stream';
    }

    protected function extensionForType(string $type): string
    {
        return $type === 'jpeg' ? 'jpg' : $type;
    }

    protected function ensureDirectoryExists(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!mkdir($dir, $this->dirPerm ?? 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create cache directory: {$dir}");
        }
        if ($this->dirOwner) {
            @chown($dir, $this->dirOwner);
        }
        if ($this->dirGroup) {
            @chgrp($dir, $this->dirGroup);
        }
        if ($this->dirPerm) {
            @chmod($dir, $this->dirPerm);
        }
    }

    protected function generateDerivedImageWithLock(string $src, string $dest, int $size, string $type, bool $min): void
    {
        $lock = $dest . '.lock';
        $fp = fopen($lock, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Unable to open lock file');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire lock');
            }
            // Double-check if another process created the file while waiting
            if (is_file($dest) && is_readable($dest)) {
                return;
            }

            $this->generateImage($src, $dest, $size, $type, 90, $min);

            if ($this->filePerm !== null) {
                @chmod($dest, $this->filePerm);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
            @unlink($lock);
        }
    }

    /**
     * Generate a resized image and write to $dest atomically (write tmp, rename).
     */
    protected function generateImage(string $srcFile, string $destFile, int $size, string $type, int $quality = 90, bool $min = false): void
    {
        if (!function_exists('getimagesize')) {
            throw new RuntimeException('GD functions not available');
        }

        $info = getimagesize($srcFile);
        if ($info === false) {
            throw new RuntimeException('Invalid source image');
        }
        [$srcW, $srcH] = [$info[0], $info[1]];

        if ($min === false) {
            // scale so the longest side equals $size
            if ($srcW >= $srcH) {
                $dstW = $size;
                $dstH = (int) round($size * ($srcH / $srcW));
            } else {
                $dstH = $size;
                $dstW = (int) round($size * ($srcW / $srcH));
            }
        } else {
            // ensure both sides are at least $size
            if ($srcW >= $srcH) {
                $dstH = $size;
                $dstW = (int) round($size * ($srcW / $srcH));
            } else {
                $dstW = $size;
                $dstH = (int) round($size * ($srcH / $srcW));
            }
        }

        if ($dstW <= 0 || $dstH <= 0) {
            throw new RuntimeException('Computed zero-sized destination');
        }

        $dstImg = imagecreatetruecolor($dstW, $dstH);
        if ($dstImg === false) {
            throw new RuntimeException('Unable to allocate destination image');
        }

        // Create source image resource based on type
        $createFn = match ($type) {
            'jpeg' => 'imagecreatefromjpeg',
            'png'  => 'imagecreatefrompng',
            'gif'  => 'imagecreatefromgif',
            default => throw new RuntimeException('Unsupported image type'),
        };

        $srcImg = $createFn($srcFile);
        if ($srcImg === false) {
            imagedestroy($dstImg);
            throw new RuntimeException('Unable to create source image resource');
        }

        // Preserve transparency for PNG/GIF
        if ($type === 'png' || $type === 'gif') {
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $transparent);
        } else {
            // Fill white background for JPEG
            $white = imagecolorallocate($dstImg, 255, 255, 255);
            imagefilledrectangle($dstImg, 0, 0, $dstW, $dstH, $white);
        }

        if (!imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH)) {
            imagedestroy($dstImg);
            imagedestroy($srcImg);
            throw new RuntimeException('Image resampling failed');
        }

        // Write to temp file then rename
        $tmp = $destFile . '.tmp-' . bin2hex(random_bytes(6));

        // Make JPEG progressive (interlaced) to improve perceived load
        if ($type === 'jpeg') {
            imageinterlace($dstImg, true);
        }

        // Use a sane PNG compression level (0-9). 6 is a good default (balanced).
        $pngCompressionLevel = 6;

        $saved = match ($type) {
            'jpeg' => imagejpeg($dstImg, $tmp, $quality),
            'png'  => imagepng($dstImg, $tmp, $pngCompressionLevel),
            'gif'  => imagegif($dstImg, $tmp),
        };

        imagedestroy($dstImg);
        imagedestroy($srcImg);

        if (!$saved) {
            @unlink($tmp);
            throw new RuntimeException('Failed to save derived image');
        }

        if (!rename($tmp, $destFile)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move derived image into place');
        }
    }

    /**
     * Build a lightweight response array (no PSR-7 dependency).
     * The 'body_stream' is a readable resource opened in rb mode.
     */
    protected function buildResponseFromFile(string $file): array
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file) ?: 'application/octet-stream';
        finfo_close($finfo);

        $etag = '"' . md5(filemtime($file) . filesize($file)) . '"';
        $last = gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT';

        $body = fopen($file, 'rb');
        if ($body === false) {
            throw new RuntimeException('Unable to open file for reading');
        }

        $headers = [
            'Content-Type'   => $mime,
            'Content-Length' => (string) filesize($file),
            'Last-Modified'  => $last,
            'ETag'           => $etag,
            'Cache-Control'  => 'public, max-age=31536000, immutable',
        ];

        return [
            'status'      => 200,
            'headers'     => $headers,
            'body_stream' => $body,
        ];
    }
}