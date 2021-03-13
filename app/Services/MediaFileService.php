<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2021 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Services;

use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

use function array_combine;
use function array_diff;
use function assert;
use function dirname;
use function ini_get;
use function intdiv;
use function min;
use function pathinfo;
use function preg_replace;
use function sha1;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function strtr;
use function substr;
use function trim;

use const PATHINFO_EXTENSION;
use const UPLOAD_ERR_OK;

/**
 * Managing media files.
 */
class MediaFileService
{
    public const EDIT_RESTRICTIONS = [
        'locked',
    ];

    public const PRIVACY_RESTRICTIONS = [
        'none',
        'privacy',
        'confidential',
    ];

    public const EXTENSION_TO_FORM = [
        'jpg' => 'jpeg',
        'tif' => 'tiff',
    ];

    /**
     * What is the largest file a user may upload?
     */
    public function maxUploadFilesize(): string
    {
        $sizePostMax = $this->parseIniFileSize(ini_get('post_max_size'));
        $sizeUploadMax = $this->parseIniFileSize(ini_get('upload_max_filesize'));

        $bytes =  min($sizePostMax, $sizeUploadMax);
        $kb    = intdiv($bytes + 1023, 1024);

        return I18N::translate('%s KB', I18N::number($kb));
    }

    /**
     * Returns the given size from an ini value in bytes.
     *
     * @param string $size
     *
     * @return int
     */
    private function parseIniFileSize(string $size): int
    {
        $number = (int) $size;

        switch (substr($size, -1)) {
            case 'g':
            case 'G':
                return $number * 1073741824;
            case 'm':
            case 'M':
                return $number * 1048576;
            case 'k':
            case 'K':
                return $number * 1024;
            default:
                return $number;
        }
    }

    /**
     * A list of key/value options for media types.
     *
     * @param string $current
     *
     * @return array<int|string,string>
     *
     * @deprecated - Will be removed in 2.1.0 - use Registry::elementFactory()->make('OBJE:FILE:FORM:TYPE')->values()
     */
    public function mediaTypes($current = ''): array
    {
        return Registry::elementFactory()->make('OBJE:FILE:FORM:TYPE')->values();
    }

    /**
     * A list of media files not already linked to a media object.
     *
     * @param Tree               $tree
     * @param FilesystemOperator $data_filesystem
     *
     * @return array<string>
     * @throws FilesystemException
     */
    public function unusedFiles(Tree $tree, FilesystemOperator $data_filesystem): array
    {
        $used_files = DB::table('media_file')
            ->where('m_file', '=', $tree->id())
            ->where('multimedia_file_refn', 'NOT LIKE', 'http://%')
            ->where('multimedia_file_refn', 'NOT LIKE', 'https://%')
            ->pluck('multimedia_file_refn')
            ->all();

        $media_filesystem = $disk_files = $tree->mediaFilesystem($data_filesystem);
        $disk_files       = $this->allFilesOnDisk($media_filesystem, '', Filesystem::LIST_DEEP)->all();
        $unused_files     = array_diff($disk_files, $used_files);

        sort($unused_files);

        return array_combine($unused_files, $unused_files);
    }

    /**
     * Store an uploaded file (or URL), either to be added to a media object
     * or to create a media object.
     *
     * @param ServerRequestInterface $request
     *
     * @return string The value to be stored in the 'FILE' field of the media object.
     * @throws FilesystemException
     */
    public function uploadFile(ServerRequestInterface $request): string
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        $data_filesystem = Registry::filesystem()->data();

        $params        = (array) $request->getParsedBody();
        $file_location = $params['file_location'];

        switch ($file_location) {
            case 'url':
                $remote = $params['remote'];

                if (str_contains($remote, '://')) {
                    return $remote;
                }

                return '';

            case 'unused':
                $unused = $params['unused'];

                if ($tree->mediaFilesystem($data_filesystem)->fileExists($unused)) {
                    return $unused;
                }

                return '';

            case 'upload':
            default:
                $folder   = $params['folder'];
                $auto     = $params['auto'];
                $new_file = $params['new_file'];

                /** @var UploadedFileInterface|null $uploaded_file */
                $uploaded_file = $request->getUploadedFiles()['file'];
                if ($uploaded_file === null || $uploaded_file->getError() !== UPLOAD_ERR_OK) {
                    return '';
                }

                // The filename
                $new_file = strtr($new_file, ['\\' => '/']);
                if ($new_file !== '' && !str_contains($new_file, '/')) {
                    $file = $new_file;
                } else {
                    $file = $uploaded_file->getClientFilename();
                }

                // The folder
                $folder = strtr($folder, ['\\' => '/']);
                $folder = trim($folder, '/');
                if ($folder !== '') {
                    $folder .= '/';
                }

                // Generate a unique name for the file?
                if ($auto === '1' || $tree->mediaFilesystem($data_filesystem)->fileExists($folder . $file)) {
                    $folder    = '';
                    $extension = pathinfo($uploaded_file->getClientFilename(), PATHINFO_EXTENSION);
                    $file      = sha1((string) $uploaded_file->getStream()) . '.' . $extension;
                }

                try {
                    $tree->mediaFilesystem($data_filesystem)->writeStream($folder . $file, $uploaded_file->getStream()->detach());

                    return $folder . $file;
                } catch (RuntimeException | InvalidArgumentException $ex) {
                    FlashMessages::addMessage(I18N::translate('There was an error uploading your file.'));

                    return '';
                }
        }
    }

    /**
     * Convert the media file attributes into GEDCOM format.
     *
     * @param string $file
     * @param string $type
     * @param string $title
     * @param string $note
     *
     * @return string
     */
    public function createMediaFileGedcom(string $file, string $type, string $title, string $note): string
    {
        // Tidy non-printing characters
        $type  = trim(preg_replace('/\s+/', ' ', $type));
        $title = trim(preg_replace('/\s+/', ' ', $title));

        $gedcom = '1 FILE ' . $file;

        $format = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $format = self::EXTENSION_TO_FORM[$format] ?? $format;

        if ($format !== '') {
            $gedcom .= "\n2 FORM " . $format;
        } elseif ($type !== '') {
            $gedcom .= "\n2 FORM";
        }

        if ($type !== '') {
            $gedcom .= "\n3 TYPE " . $type;
        }

        if ($title !== '') {
            $gedcom .= "\n2 TITL " . $title;
        }

        if ($note !== '') {
            // Convert HTML line endings to GEDCOM continuations
            $gedcom .= "\n1 NOTE " . strtr($note, ["\r\n" => "\n2 CONT "]);
        }

        return $gedcom;
    }

    /**
     * Fetch a list of all files on disk (in folders used by any tree).
     *
     * @param FilesystemOperator $filesystem $filesystem to search
     * @param string             $folder     Root folder
     * @param bool               $subfolders Include subfolders
     *
     * @return Collection<string>
     * @throws FilesystemException
     */
    public function allFilesOnDisk(FilesystemOperator $filesystem, string $folder, bool $subfolders): Collection
    {
        $files = $filesystem->listContents($folder, $subfolders)
            ->filter(function (StorageAttributes $attributes): bool {
                return $attributes->isFile() && !$this->isLegacyFolder($attributes->path());
            })
            ->map(static function (StorageAttributes $attributes): string {
                return $attributes->path();
            });

        return new Collection($files->toArray());
    }

    /**
     * Fetch a list of all files on in the database.
     *
     * @param string $media_folder Root folder
     * @param bool   $subfolders   Include subfolders
     *
     * @return Collection<string>
     */
    public function allFilesInDatabase(string $media_folder, bool $subfolders): Collection
    {
        $query = DB::table('media_file')
            ->join('gedcom_setting', 'gedcom_id', '=', 'm_file')
            ->where('setting_name', '=', 'MEDIA_DIRECTORY')
            //->where('multimedia_file_refn', 'LIKE', '%/%')
            ->where('multimedia_file_refn', 'NOT LIKE', 'http://%')
            ->where('multimedia_file_refn', 'NOT LIKE', 'https://%')
            ->where(new Expression('setting_value || multimedia_file_refn'), 'LIKE', $media_folder . '%')
            ->select(new Expression('setting_value || multimedia_file_refn AS path'))
            ->orderBy(new Expression('setting_value || multimedia_file_refn'));

        if (!$subfolders) {
            $query->where(new Expression('setting_value || multimedia_file_refn'), 'NOT LIKE', $media_folder . '%/%');
        }

        return $query->pluck('path');
    }

    /**
     * Generate a list of all folders in either the database or the filesystem.
     *
     * @param FilesystemOperator $data_filesystem
     *
     * @return Collection<string,string>
     * @throws FilesystemException
     */
    public function allMediaFolders(FilesystemOperator $data_filesystem): Collection
    {
        $db_folders = DB::table('media_file')
            ->join('gedcom_setting', 'gedcom_id', '=', 'm_file')
            ->where('setting_name', '=', 'MEDIA_DIRECTORY')
            ->where('multimedia_file_refn', 'NOT LIKE', 'http://%')
            ->where('multimedia_file_refn', 'NOT LIKE', 'https://%')
            ->select(new Expression('setting_value || multimedia_file_refn AS path'))
            ->pluck('path')
            ->map(static function (string $path): string {
                return dirname($path) . '/';
            });

        $media_roots = DB::table('gedcom_setting')
            ->where('setting_name', '=', 'MEDIA_DIRECTORY')
            ->where('gedcom_id', '>', '0')
            ->pluck('setting_value')
            ->uniqueStrict();

        $disk_folders = new Collection($media_roots);

        foreach ($media_roots as $media_folder) {
            $tmp = $data_filesystem->listContents($media_folder, Filesystem::LIST_DEEP)
                ->filter(function (StorageAttributes $attributes): bool {
                    return $attributes->isDir() && !$this->isLegacyFolder($attributes->path());
                })
                ->map(static function (StorageAttributes $attributes): string {
                    return $attributes->path() . '/';
                })
                ->toArray();

            $disk_folders = $disk_folders->concat($tmp);
        }

        return $disk_folders->concat($db_folders)
            ->uniqueStrict()
            ->mapWithKeys(static function (string $folder): array {
                return [$folder => $folder];
            });
    }

    /**
     * Some special media folders were created by earlier versions of webtrees.
     *
     * @param string $path
     *
     * @return bool
     */
    private function isLegacyFolder(string $path): bool
    {
        return
            str_starts_with($path, 'thumbs/') ||
            str_contains($path, '/thumbs/') ||
            str_ends_with($path, '/thumbs') ||
            str_starts_with($path, 'watermarks/') ||
            str_contains($path, '/watermarks/') ||
            str_ends_with($path, '/watermarks');
    }
}
