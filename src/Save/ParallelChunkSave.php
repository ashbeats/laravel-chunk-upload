<?php
namespace Pion\Laravel\ChunkUpload\Save;

use File;
use Illuminate\Http\UploadedFile;
use Pion\Laravel\ChunkUpload\Config\AbstractConfig;
use Pion\Laravel\ChunkUpload\Exceptions\ChunkSaveException;
use Pion\Laravel\ChunkUpload\Handler\ParallelUploadHandler;
use Pion\Laravel\ChunkUpload\Storage\ChunkStorage;

class ParallelChunkSave extends AbstractSave
{
    /**
     * Is this the final chunk?
     *
     * @var bool
     */
    protected $isLastChunk;

    /**
     * What is the chunk file name
     *
     * @var string
     */
    protected $chunkFileName;

    /**
     * The chunk file path
     *
     * @var string
     */
    protected $chunkFullFilePath = null;

    /**
     * @var UploadedFile|null
     */
    protected $fullChunkFile;

    /**
     * @var ChunkStorage
     */
    private $chunkStorage;


    private $handlerx;

    /**
     * AbstractUpload constructor.
     *
     * @param UploadedFile $file the uploaded file (chunk file)
     * @param ParallelUploadHandler $handler the handler that detected the correct save method
     * @param ChunkStorage $chunkStorage the chunk storage
     * @param AbstractConfig $config the config manager
     */
    public function __construct(UploadedFile $file, ParallelUploadHandler $handler, $chunkStorage, $config)
    {
        parent::__construct($file, $handler, $config);


        $this->chunkStorage = $chunkStorage;

        $this->isLastChunk = false; #not relying on this. Instead use handler()->haveAllChunksArrived() to get a up-to-the-minute status.

        $this->chunkFileName = $handler->getChunkFileName();

        // build the full disk path
        $this->chunkFullFilePath = $this->getChunkFilePath(true);

        $this->handleChunkMerge();
    }


    /**
     * Checks if the file upload is finished (last chunk)
     *
     * @return bool
     */
    public function isFinished()
    {
        //return parent::isFinished() && $this->isLastChunk;
        return $this->handler()->haveAllChunksArrived();
    }

    /**
     * Returns the chunk file path in the current disk instance
     *
     * @param bool $absolutePath
     *
     * @return string
     */
    public function getChunkFilePath($absolutePath = false)
    {
        return $this->getChunkDirectory($absolutePath) . $this->chunkFileName;
    }


    /**
     * Returns the full file path
     *
     * @return string
     */
    public function getChunkFullFilePath()
    {
        return $this->chunkFullFilePath;
    }

    /**
     * Returns the folder for the cunks in the storage path on current disk instance
     *
     * @param boolean $absolutePath
     *
     * @return string
     */
    public function getChunkDirectory($absolutePath = false)
    {
        $paths = [];

        if ($absolutePath) {
            $paths[] = $this->chunkStorage()->getDiskPathPrefix();
        }

        $paths[] = $this->chunkStorage()->directory();

        return implode("", $paths);
    }

    /**
     * Returns the uploaded file if the chunk if is not completed, otherwise passes the
     * final chunk file
     *
     * @return null|UploadedFile
     */
    public function getFile()
    {
        if ($this->handler()->haveAllChunksArrived()) {

            if (!$this->fullChunkFile) {
                $this->mergeAllChunks();
            }

            return $this->fullChunkFile;
        }


        return parent::getFile();
    }

    /**
     * Appends the new uploaded data to the final file
     *
     * @throws ChunkSaveException
     */
    protected function handleChunkMerge()
    {
        // prepare the folder and file path
        $this->createChunksFolderIfNeeded();
        $file = $this->getChunkFilePath();

        $this->saveCurrentChunkFile();

        // build the last file because of the last chunk
       /* if ($this->handler()->haveAllChunksArrived()) {
            $this->mergeAllChunks();
        }*/

    }

    function mergeAllChunks()
    {

        $sections = $this->handler()->getAllSections();

        # C:\xampp-root-4\storage\app\chunks/
        $chunkFolderAbsolute = ($this->getChunkDirectory(true));

        # chunks/
        $chunkFolderName = $this->getChunkDirectory();

        # make a reasonable name. ( can-stock-photo_csp23570125.webm-cea02e91a6755040b9377c50b50380e0 )
        $unique_file_prefix = $this->handler()->extractUniqueFilePrefix($this->chunkFileName);

        $finalPath = $chunkFolderAbsolute . "/" . $unique_file_prefix . ".combined";

        $finalPath = preg_replace('%\\\\+|/+%i', DIRECTORY_SEPARATOR, $finalPath);


//        dd('$finalPath', $finalPath);
        # C:\xampp-root-4\storage\app\chunks\can-stock-photo_csp25996973.webm-cea02e91a6755040b9377c50b50380e0.combined
        /*if (!touch($finalPath)) {
            throw new ChunkSaveException('touch failed.', 803);
        }*/

//        $finalPath = realpath($finalPath);

        /** Sort chunks in order */
        $sections = (collect($sections))->sort(function ($left, $right) {
            $sectionIndexLeft = $this->handler()->extractSectionIndex($left);
            $sectionIndexRight = $this->handler()->extractSectionIndex($right);

            if ($sectionIndexLeft == $sectionIndexRight) {
                return 0;
            }

            return ($sectionIndexLeft < $sectionIndexRight) ? -1 : 1;
        });


        foreach ($sections->toArray() as $index => $section) {

            # subtract chunks/ from the end of C:\xampp-root-4\storage\app\chunks/
            $sectionFilename = preg_replace('/^' . preg_quote($chunkFolderName, '/') . '/im', '', $section);;
            $actual_section_file_path = realpath($chunkFolderAbsolute . "/" . $sectionFilename);

            if (!$actual_section_file_path) {
                throw new ChunkSaveException('Merging failed.', 802);
            }

            // use direct streams to keep memory usage low instaed of fopen/fread
            file_put_contents($finalPath, file_get_contents($actual_section_file_path), FILE_APPEND);

        }

        // build the new UploadedFile
        $this->fullChunkFile = new UploadedFile(
            $finalPath,
            $this->file->getClientOriginalName(),
            mime_content_type($finalPath),
            filesize($finalPath), $this->file->getError(),
            true # fake test mode.
        );


    }

    /***
     * Clear sections after file has been saved.
     *
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\ChunkSaveException
     */
    public function clearRelatedChunks()
    {
        /****
         * Call this function from the UploadsController.
         * Example:
         * ` if ($save->isFinished()) {
         * // save the file and return any response you need
         * $response = $this->saveFile($save->getFile());
         *
         * // clear the chunks/sections first.
         *
         * if($response){
         * $save->clearRelatedChunks(); <------
         * }
         *
         * return $response; <-- then send back response.`
         *
         *
         * }*/

        $sections = $this->handler()->getAllSections();

        $chunkFolderAbsolute = realpath($this->getChunkDirectory(true));
        $unique_file_prefix = $this->handler()->extractUniqueFilePrefix($this->chunkFileName);

        $finalPath = $chunkFolderAbsolute . "/" . $unique_file_prefix . "--section-*";


        foreach (File::glob($finalPath) as $spentSection) {
            @File::delete($spentSection);
        }


    }

    /**
     * Appends the current uploaded file data to a chunk file
     *
     * @throws ChunkSaveException
     */
    protected function saveCurrentChunkFile()
    {
        $temp_source = $this->file->getPathname(); // sys temp file
        $destination = $this->getChunkFullFilePath(); // our custom part filename.

        try {

            $destination_dir = realpath($this->getChunkDirectory(true));
            if(file_exists($destination_dir . "/" . $this->chunkFileName)){
                File::delete($destination_dir. "/" . $this->chunkFileName);
            }

            $this->file->move($destination_dir, $this->chunkFileName);
        } catch (\Exception $e) {
            throw new ChunkSaveException($e->getMessage() ?? 'Move failed.', 801);
        }

    }


    /**
     * Builds the final file
     */
    protected function buildFullFileFromChunks()
    {
        // ...
    }

    /**
     * Appends the current uploaded file data to a chunk file
     *
     * @throws ChunkSaveException
     */
    protected function appendDataToChunkFile()
    {
        //...
    }

    /**
     * Returns the current chunk storage
     *
     * @return ChunkStorage
     */
    public function chunkStorage()
    {
        return $this->chunkStorage;
    }

    /**
     * Returns the disk adapter for the chunk
     *
     * @return \Illuminate\Filesystem\FilesystemAdapter
     */
    public function chunkDisk()
    {
        return $this->chunkStorage()->disk();
    }

    /**
     * Crates the chunks folder if doesn't exists. Uses recursive create
     */
    protected function createChunksFolderIfNeeded()
    {
        $path = $this->getChunkDirectory(true);

        // creates the chunks dir
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
    }
}
