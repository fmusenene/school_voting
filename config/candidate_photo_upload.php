<?php

declare(strict_types=1);

/**
 * Candidate photo upload helpers — broad image formats, large high-quality files.
 */

/** Application max upload size (30 MB). */
function candidate_photo_app_max_bytes(): int
{
    return 30 * 1024 * 1024;
}

/** Parse php.ini size values like "8M", "2G". */
function candidate_photo_parse_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    if ($unit === 'g') {
        return (int) ($number * 1024 * 1024 * 1024);
    }
    if ($unit === 'm') {
        return (int) ($number * 1024 * 1024);
    }
    if ($unit === 'k') {
        return (int) ($number * 1024);
    }
    return (int) $number;
}

/** Effective max bytes (respects php.ini upload/post limits). */
function candidate_photo_max_upload_bytes(): int
{
    $app = candidate_photo_app_max_bytes();
    $uploadIni = candidate_photo_parse_ini_bytes((string) ini_get('upload_max_filesize'));
    $postIni = candidate_photo_parse_ini_bytes((string) ini_get('post_max_size'));
    $limits = array_filter([$app, $uploadIni, $postIni], static fn(int $n): bool => $n > 0);
    return min($limits);
}

function candidate_photo_max_size_label(): string
{
    $mb = (int) round(candidate_photo_max_upload_bytes() / (1024 * 1024));
    return $mb . 'MB';
}

/** Extensions allowed for candidate photos. */
function candidate_photo_allowed_extensions(): array
{
    return [
        'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'bmp',
        'tif', 'tiff', 'heic', 'heif', 'avif', 'img',
    ];
}

/** HTML accept attribute for file inputs. */
function candidate_photo_accept_attribute(): string
{
    return 'image/*,.heic,.heif,.avif,.img';
}

/** Human-readable format list for UI. */
function candidate_photo_formats_label(): string
{
    return 'JPG, JPEG, PNG, GIF, WEBP, BMP, TIFF, HEIC, HEIF, AVIF, IMG, and other common image formats';
}

function candidate_photo_upload_dir_absolute(): string
{
    $projectRoot = dirname(__DIR__);
    return $projectRoot . '/uploads/candidates/';
}

function candidate_photo_upload_dir_relative(): string
{
    return 'uploads/candidates/';
}

/**
 * @return array<string, list<string>>
 */
function candidate_photo_allowed_mimes(): array
{
    return [
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'jpe'  => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png', 'image/x-png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'tif'  => ['image/tiff', 'image/x-tiff'],
        'tiff' => ['image/tiff', 'image/x-tiff'],
        'heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'application/octet-stream'],
        'heif' => ['image/heif', 'image/heic', 'application/octet-stream'],
        'avif' => ['image/avif'],
        'img'  => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/tiff', 'image/heic', 'image/heif', 'application/octet-stream'],
    ];
}

function candidate_photo_detect_mime(string $tmpPath): string
{
    if (!is_readable($tmpPath)) {
        return '';
    }
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return strtolower($mime);
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
        if (is_string($mime) && $mime !== '') {
            return strtolower($mime);
        }
    }
    return '';
}

function candidate_photo_extension_from_mime(string $mime): ?string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/tiff' => 'jpg',
        'image/x-tiff' => 'jpg',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'image/avif' => 'avif',
    ];
    return $map[$mime] ?? null;
}

function candidate_photo_validate_file(array $file): void
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: Code ' . (int) ($file['error'] ?? 0));
    }

    $maxBytes = candidate_photo_max_upload_bytes();
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new Exception(
            'File is too large. Maximum size allowed is ' . candidate_photo_max_size_label() . '.'
        );
    }

    $name = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowedExt = candidate_photo_allowed_extensions();
    if ($extension !== '' && !in_array($extension, $allowedExt, true)) {
        throw new Exception(
            'Invalid file type. Allowed: ' . candidate_photo_formats_label() . '.'
        );
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $mime = candidate_photo_detect_mime($tmp);

    if ($mime === '') {
        if ($extension === '' || !in_array($extension, $allowedExt, true)) {
            throw new Exception('Could not verify the uploaded image type.');
        }
        return;
    }

    if (strpos($mime, 'image/') !== 0 && $mime !== 'application/octet-stream') {
        throw new Exception('Invalid file type. Please upload an image file.');
    }

    if ($extension === '') {
        $extension = candidate_photo_extension_from_mime($mime) ?? '';
        if ($extension === '' && strpos($mime, 'image/') === 0) {
            $extension = 'jpg';
        }
    }

    $mimeMap = candidate_photo_allowed_mimes();
    if ($extension !== '' && isset($mimeMap[$extension])) {
        $ok = in_array($mime, $mimeMap[$extension], true)
            || strpos($mime, 'image/') === 0;
        if (!$ok && in_array($extension, ['heic', 'heif', 'img'], true)) {
            $ok = ($mime === 'application/octet-stream' || strpos($mime, 'image/') === 0);
        }
        if (!$ok) {
            throw new Exception('File content does not match the selected image type.');
        }
    }
}

/**
 * Convert HEIC/HEIF to JPEG when ImageMagick is available (better browser support).
 */
function candidate_photo_normalize_saved_file(string $absolutePath, string $extension): string
{
    $extension = strtolower($extension);
    if (!in_array($extension, ['heic', 'heif'], true)) {
        return $extension;
    }

    if (!extension_loaded('imagick') || !class_exists('Imagick')) {
        return $extension;
    }

    try {
        $imagick = new Imagick($absolutePath);
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(92);
        $jpegPath = preg_replace('/\.[^.]+$/i', '.jpg', $absolutePath) ?? ($absolutePath . '.jpg');
        $imagick->writeImage($jpegPath);
        $imagick->clear();
        $imagick->destroy();
        if (is_file($absolutePath) && $jpegPath !== $absolutePath) {
            @unlink($absolutePath);
        }
        return 'jpg';
    } catch (Throwable $e) {
        error_log('HEIC conversion failed, keeping original: ' . $e->getMessage());
        return $extension;
    }
}

/**
 * Upload candidate photo from $_FILES input name or file array.
 *
 * @param string|array $fileInputNameOrArray
 */
function handle_candidate_photo_upload($fileInputNameOrArray): string
{
    if (is_string($fileInputNameOrArray)) {
        if (!isset($_FILES[$fileInputNameOrArray]) || $_FILES[$fileInputNameOrArray]['error'] === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        $file = $_FILES[$fileInputNameOrArray];
    } else {
        $file = $fileInputNameOrArray;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }
    }

    candidate_photo_validate_file($file);

    $uploadDir = candidate_photo_upload_dir_absolute();
    $relativeBase = candidate_photo_upload_dir_relative();

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new Exception('Failed to create upload directory. Check permissions.');
    }
    if (!is_writable($uploadDir)) {
        throw new Exception('Upload directory is not writable.');
    }

    $name = (string) ($file['name'] ?? 'photo');
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($extension === '') {
        $mime = candidate_photo_detect_mime((string) $file['tmp_name']);
        $extension = candidate_photo_extension_from_mime($mime) ?? 'jpg';
    }

    $newFilename = uniqid('cand_', true) . '.' . $extension;
    $targetAbsolute = $uploadDir . $newFilename;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetAbsolute)) {
        throw new Exception('Failed to move uploaded file. Check server logs.');
    }

    $finalExt = candidate_photo_normalize_saved_file($targetAbsolute, $extension);
    if ($finalExt !== $extension) {
        $targetAbsolute = preg_replace('/\.[^.]+$/i', '.' . $finalExt, $targetAbsolute) ?? $targetAbsolute;
        $newFilename = basename($targetAbsolute);
    }

    return $relativeBase . $newFilename;
}

function delete_candidate_photo_file(?string $relativePhotoPath): bool
{
    if (empty($relativePhotoPath)) {
        return false;
    }

    $projectRoot = dirname(__DIR__);
    $absolutePath = $projectRoot . '/' . ltrim($relativePhotoPath, '/');
    $uploadsRoot = realpath($projectRoot . '/uploads/candidates');

    if ($uploadsRoot === false) {
        return false;
    }

    $realFile = realpath($absolutePath);
    if ($realFile === false || !is_file($realFile)) {
        return false;
    }

    if (strpos($realFile, $uploadsRoot) !== 0) {
        error_log('Attempt to delete file outside candidates upload directory: ' . $absolutePath);
        return false;
    }

    if (!unlink($realFile)) {
        error_log('Failed to delete candidate photo file: ' . $realFile);
        return false;
    }

    return true;
}

/** Backward-compatible aliases used by admin scripts. */
function handleFileUpload($file_input_name)
{
    return handle_candidate_photo_upload($file_input_name);
}

function deletePhotoFile($relative_photo_path)
{
    return delete_candidate_photo_file($relative_photo_path);
}
