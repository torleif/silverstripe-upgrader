<?php

namespace SilverStripe\Upgrader\CodeCollection;

use InvalidArgumentException;

/**
 * Represents a set of code changes and warnings.
 * Generated by an Upgrader, to be displayed with a ChangeDisplay or written to disk with a CodeCollection.
 */
class CodeChangeSet
{
    /**
     * List of changes for files.
     * Each change can represent content update or a file operation. The array is structure as such:
     * ```php
     * [
     *   'updatedFile.txt' => [
     *     'new' => 'framework',
     *     'old' => 'sapphire',
     *     'path' => 'updatedFile.txt'
     *   ],
     *   'brandNewFile.txt' => [
     *     'new' => 'framework',
     *     'old' => null,
     *     'path' => 'brandNewFile.txt'
     *   ],
     *   'moveFileWithUpdatedContent.txt' => [
     *     'new' => 'framework',
     *     'old' => 'sapphire',
     *     'path' => 'newPath.txt
     *   ],
     *   'deletedFile.txt' => [
     *     'new' => null,
     *     'old' => 'sapphire',
     *     'path' => null
     *   ],
     *   'moveFolder' => [
     *      'path' => 'newFolderPath'
     *   ],
     *
     * ]
     * ```
     *
     * @var array
     */
    private $fileChanges = [];

    private $warnings = [];

    private $affectedFiles = [];

    /**
     * Add a file change.
     *
     * @param string $path
     * @param string|null $contents New contents. Can be set to null if moving a file without altering content.
     * @param string|null $original Original contents. Can be set to null when moving file without altering content or
     *                              adding a brand new file.
     * @param string|null $newPath New location of the file. Leave to null if the location is not changing.
     * @return void
     */
    public function addFileChange(string $path, $contents, $original, $newPath = null): void
    {
        if ($this->hasNewContents($path)) {
            user_error("Already added changes for $path, shouldn't add a 2nd time");
        }

        $change = [
            'path' => $newPath ?: $path
        ];

        if ($contents !== $original) {
            $change['new'] = $contents;
            $change['old'] = $original;
        }

        $this->fileChanges[$path] = $change;

        $this->addToAffectedFiles($path);
    }

    /**
     * Move a file/folder to a different location within the project.
     * @param string $path
     * @param string $newPath
     * @return void
     */
    public function move(string $path, string $newPath): void
    {
        $this->addFileChange($path, null, null, $newPath);
    }

    /**
     * Remove a file from the project.
     * @param string $path
     * @return void
     */
    public function remove(string $path): void
    {
        if ($this->hasNewContents($path)) {
            user_error("Already added changes for $path, shouldn't add a 2nd time");
        }
        $this->fileChanges[$path] = ['path' => null];

        $this->addToAffectedFiles($path);
    }

    /**
     * Add a warning about a given file.
     * Usually these warnings highlight upgrade activity that a developer will need to check for themselves
     *
     * @param string $path
     * @param integer $line
     * @param string $warning
     * @return void
     */
    public function addWarning(string $path, int $line, string $warning): void
    {
        if (!isset($this->warnings[$path])) {
            $this->warnings[$path] = [];
        }

        $this->warnings[$path][] = "<info>$path:$line</info> <comment>$warning</comment>";

        $this->addToAffectedFiles($path);
    }

    /**
     * Add multiple warnings a to the given file.
     * @param string $path
     * @param string[] $warnings
     * @return void
     */
    public function addWarnings(string $path, array $warnings): void
    {
        foreach ($warnings as $warning) {
            list($line, $message) = $warning;
            $this->addWarning($path, $line, $message);
        }
    }

    /**
     * Return all the file changes, as a map of path => array(new => '', old => '')
     *
     * @return array
     */
    public function allChanges()
    {
        return $this->fileChanges;
    }

    /**
     * Return all affected files, in the order that they were added to the CodeChangeSet
     * @return array
     */
    public function affectedFiles()
    {
        return array_values($this->affectedFiles);
    }

    /**
     * Returns true if the given path has new content.
     * @param string $path
     * @return boolean
     */
    public function hasNewContents(string $path): bool
    {
        if (isset($this->fileChanges[$path])) {
            $change = $this->fileChanges[$path];
            return
                isset($change['new']) &&
                $change['new'] !== null &&
                !(isset($change['old']) && $change['old'] == $change['new']);
        }
        return false;
    }

    /**
     * Returns true if the given path has warnings in this change set.
     * @param string $path
     * @return boolean
     */
    public function hasWarnings(string $path): bool
    {
        return isset($this->warnings[$path]);
    }

    /**
     * Return the file contents for a given path
     *
     * @param string $path
     * @return string|null
     * @throws InvalidArgumentException If `$path` has not been recorded as changed.
     */
    public function newContents(string $path)
    {
        $change = $this->changeByPath($path);
        return isset($change['new']) ? $change['new'] : null;
    }

    /**
     * Return the prior file contents for a given path
     *
     * @param string $path
     * @return string|null
     * @throws InvalidArgumentException If `$path` has not been recorded as changed.
     */
    public function oldContents(string $path)
    {
        $change = $this->changeByPath($path);
        return isset($change['old']) ? $change['old'] : null;
    }

    /**
     * Return the new Path for a file.
     * @param string $path
     * @return string|null
     * @throws InvalidArgumentException If `$path` has not been recorded as changed.
     */
    public function newPath(string $path)
    {
        return $this->changeByPath($path)['path'];
    }

    /**
     * Return the operation that should be applied for the provided path. Will be one of:
     * * `modified` for file with updated content,
     * * `renamed` for files or folder that should be moved to a different location, with or without modifications,
     * * `deleted` for files or folder that should be deleted,
     * * `` for files that don't appear to have any outstanding operation against them.
     * @param string $path
     * @return string
     */
    public function opsByPath(string $path): string
    {
        if (isset($this->fileChanges[$path])) {
            $change = $this->fileChanges[$path];

            if ($change['path'] === null) {
                // If the path attribute is null, we are deleting the file
                return 'deleted';
            } elseif ($change['path'] != $path) {
                // If the change path is different than the key path we are moving the file.
                return 'renamed';
            }

            if ($this->hasNewContents($path)) {
                // If we have new contents with old content, we are modifying a file. Otherwise, it's a new file.
                return (isset($change['old']) && $change['old'] !== null) ?
                    'modified':
                    'new file';
            }
        }

        return '';
    }

    /**
     * Return the warnings for a given path
     * @param string $path
     * @throws InvalidArgumentException If `$path` does not have any warnings.
     * @return string[]
     */
    public function warningsForPath(string $path): array
    {
        if ($this->hasWarnings($path)) {
            return $this->warnings[$path];
        } else {
            throw new InvalidArgumentException("No warnings found for $path");
        }
    }

    /**
     * Merge warnings from another CodeChangeSet into this CodeChangeSet
     * @param CodeChangeSet $diff
     * @return void
     */
    public function mergeWarnings(CodeChangeSet $diff): void
    {
       // Merge warnings
        foreach ($diff->affectedFiles() as $path) {
            if ($diff->hasWarnings($path)) {
                $warnings = $diff->warningsForPath($path);
                if (isset($this->warnings[$path])) {
                    $this->warnings[$path] = array_merge($this->warnings[$path], $warnings);
                } else {
                    $this->warnings[$path] = $warnings;
                    $this->addToAffectedFiles($path);
                }
            }
        }
    }

    /**
     * Return a change set for the given path.
     * @param string $path
     * @throws InvalidArgumentException If no change have been recorded against `$path`.
     * @return array
     */
    private function changeByPath(string $path): array
    {
        if (isset($this->fileChanges[$path])) {
            return $this->fileChanges[$path];
        } else {
            throw new InvalidArgumentException("No file changes found for $path");
        }
    }

    /**
     * Add a file path to the list of affected files in the code change set.
     * @param string $path
     * @return void
     */
    private function addToAffectedFiles(string $path): void
    {
        if (!isset($this->affectedFiles[$path])) {
            $this->affectedFiles[$path] = $path;
        }
    }
}
