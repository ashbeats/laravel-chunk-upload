<?php
namespace Pion\Laravel\ChunkUpload\Save;

use File;
use Illuminate\Http\UploadedFile;
use Pion\Laravel\ChunkUpload\Config\AbstractConfig;
use Pion\Laravel\ChunkUpload\Exceptions\ChunkSaveException;
use Pion\Laravel\ChunkUpload\Handler\ResumablejsParallelUploadHandler;
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
     * @param ResumablejsParallelUploadHandler $handler the handler that detected the correct save method
     * @param ChunkStorage $chunkStorage the chunk storage
     * @param AbstractConfig $config the config manager
     */
    public function __construct(UploadedFile $file, ResumablejsParallelUploadHandler $handler, $chunkStorage, $config)
    {
        parent::__construct($file, $handler, $config);
        $this->chunkStorage = $chunkStorage;
        
        
        $this->isLastChunk = false;
        //$this->isLastChunk = $handler->haveAllChunksArrived();
        //$this->isLastChunk = $handler->countChucksReceived();
        
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
        
        // delete the old chunk
        /* if ($this->handler()->isFirstChunk() && $this->chunkDisk()->exists($file)) {
             $this->chunkDisk()->delete($file);
         }*/
        $this->saveCurrentChunkFile();
        //$this->saveCurrentChunkFile();
        
        // build the last file because of the last chunk
        if ($this->handler()->haveAllChunksArrived()) {
            //dump("handleChunkMerge", $this->handler()->getAllSections());
            //$this->buildFullFileFromChunks();
            
            $this->mergeAllChunks();
        } else {
            
        }
    }
    
    function mergeAllChunks()
    {
        
        $sections = $this->handler()->getAllSections();
        
        # C:\xampp-root-4\cinemagraph\storage\app\chunks/
        $chunkFolderAbsolute = ($this->getChunkDirectory(true));
        
        # chunks/
        $chunkFolderName = $this->getChunkDirectory();
        
        
        // $this->getChunkDirectory($absolutePath) . $this->chunkFileName;
        
        # make a reasonable name. ( can-stock-photo_csp23570125.webm-cea02e91a6755040b9377c50b50380e0 )
        $unique_file_prefix = $this->handler()->extractUniqueFilePrefix($this->chunkFileName);
        
        $finalPath = $chunkFolderAbsolute . "/" . $unique_file_prefix . ".combined";
        
        # C:\xampp-root-4\storage\app\chunks\can-stock-photo_csp25996973.webm-cea02e91a6755040b9377c50b50380e0.combined
        if (!touch($finalPath)) {
            throw new ChunkSaveException('touch failed.', 803);
        }
        
        $finalPath = realpath($finalPath);
        
        // todo - SORT!!!
        $sections = (collect($sections))->sort(function ($left, $right) {
            $sectionIndexLeft = $this->handler()->extractSectionIndex($left);
            $sectionIndexRight = $this->handler()->extractSectionIndex($right);
            
            if ($sectionIndexLeft == $sectionIndexRight) {
                return 0;
            }
            
            return ($sectionIndexLeft < $sectionIndexRight) ? -1 : 1;
        });
        
        //dd('sorted', $sections->toArray());
        //dump("finalPath - $finalPath >> ");
        
        foreach ($sections->toArray() as $index => $section) {
            
            # chunks/can-stock-photo_csp23570125.webm-cea02e91a6755040b9377c50b50380e0--section-1.part
            
            # subtract chunks/ from the end of C:\xampp-root-4\storage\app\chunks/
            $sectionFilename = preg_replace('/^' . preg_quote($chunkFolderName, '/') . '/im', '', $section);;
            $actual_section_file_path = realpath($chunkFolderAbsolute . "/" . $sectionFilename);
            
            if (!$actual_section_file_path) {
                throw new ChunkSaveException('Merging failed.', 802);
            }
            
            //dump("$actual_section_file_path >> ");
            
            // use direct streams to keep memory usage low instaed of fopen/fread
            file_put_contents($finalPath, file_get_contents($actual_section_file_path), FILE_APPEND);
            
            // todo -delete.
            //File::delete($actual_section_file_path);
            
        }
        
        
        //dump('getClientOriginalName ????', $this->file->getClientOriginalName(), mime_content_type($finalPath));
        
        // try to get local path
        
        
        // build the new UploadedFile
        // build the new UploadedFile
        $this->fullChunkFile = new UploadedFile(
            $finalPath,
            $this->file->getClientOriginalName(),
            mime_content_type($finalPath),
            filesize($finalPath), $this->file->getError(),
            true # fake test mode.
        );
        
        
        // file_put_contents( $tmp_folder . basename( $file  ), file_get_contents( $filename ), FILE_APPEND );
        
        
        //dump(['mergeAllChunks', $unique_file_prefix, $chunkFolderAbsolute, $sections, $this->chunkDisk()]);
    }
    
    /***
     * Clear sections after file has been saved.
     *
     * @throws \Pion\Laravel\ChunkUpload\Exceptions\ChunkSaveException
     */
    public function clearRelatedChunks()
    {
       /*
            Call this function from the UploadsController.
        
         if ($save->isFinished()) {
            // save the file and return any response you need
            $response = $this->saveFile($save->getFile());
        
            // clear the chunks/sections first.
        
            if($response){
                $save->clearRelatedChunks(); <------
            }
        
            return $response; <-- then send back response.
        
        
        }*/
    
        $sections = $this->handler()->getAllSections();
        
        $chunkFolderAbsolute = realpath($this->getChunkDirectory(true));
        $unique_file_prefix = $this->handler()->extractUniqueFilePrefix($this->chunkFileName);
        
        $finalPath = $chunkFolderAbsolute . "/" . $unique_file_prefix . "--section-*";
        
        
        foreach( File::glob($finalPath ) as $spentSection){
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
        // @todo: rebuild to use updateStream and etc to try to enable cloud
        // $driver = $this->chunkStorage()->driver();
        /*dd([
            '$this->getChunkFullFilePath()' => $this->getChunkFullFilePath(),
            '$this->file->getPathname()' => $this->file->getPathname(),
        ]);*/
        
        
        $temp_source = $this->file->getPathname(); // sys temp file
        $destination = $this->getChunkFullFilePath(); // our custom part filename.
        //dump($temp_source);
        
        //dd($this->getChunkDirectory(true), $this->chunkFileName);
        
        try {
            $this->file->move(realpath($this->getChunkDirectory(true)), $this->chunkFileName);
        } catch (\Exception $e) {
            throw new ChunkSaveException($e->getMessage() ?? 'Move failed.', 801);
        }
        
        // use direct streams instead of fread/fopen/fwrite to keep memory usage low.
        
        
    }
    
    
    function merge_files($file, $tmp_folder)
    {
        
        if (preg_match('%/$%im', $tmp_folder) == false) {
            $tmp_folder = $tmp_folder . "/";
        }
        
        
        $content = '';
        $items = glob($tmp_folder . basename($file) . '.*');
        
        $sort_asc_filenames = function ($filename_a, $filename_b) {
            
            $a = intval(preg_replace('/^.*\.([\d]+)$/im', '\1', $filename_a));
            $b = intval(preg_replace('/^.*\.([\d]+)$/im', '\1', $filename_b));
            
            if ($a == $b) {
                echo "a ($a) is same priority as b ($b), keeping the same\n";
                return 0;
            } else if ($a > $b) {
                echo "a ($a) is higher priority than b ($b), moving b down array\n";
                return 1;
            } else {
                echo "b ($b) is higher priority than a ($a), moving b up array\n";
                return -1;
            }
        };
        
        usort($items, $sort_asc_filenames);
        
        
        dd($sort_asc_filenames);
        
        /*    foreach ( $items as $key => $value ) {
                echo "$key: $value\n";
            }*/
        
        
        foreach ($items as $filename) {
            // use direct streams to keep memory usage low.
            file_put_contents($tmp_folder . basename($file), file_get_contents($filename), FILE_APPEND);
        }
        
        // CLean up
        foreach ($items as $filename) {
            // unlink( $filename );
            // remove folder as well.
        }
        
        return 'OK';
    }
    
    
    /**
     * Builds the final file
     */
    protected function buildFullFileFromChunks()
    {
        // try to get local path
        $finalPath = $this->getChunkFullFilePath();
        
        // build the new UploadedFile
        $this->fullChunkFile = new UploadedFile(
            $finalPath,
            $this->file->getClientOriginalName(),
            $this->file->getClientMimeType(),
            filesize($finalPath), $this->file->getError(),
            true // we must pass the true as test to force the upload file
        // to use a standard copy method, not move uploaded file
        );
    }
    
    /**
     * Appends the current uploaded file data to a chunk file
     *
     * @throws ChunkSaveException
     */
    protected function appendDataToChunkFile()
    {
        // @todo: rebuild to use updateStream and etc to try to enable cloud
        // $driver = $this->chunkStorage()->driver();
        
        // open the target file
        if (!$out = @fopen($this->getChunkFullFilePath(), 'ab')) {
            throw new ChunkSaveException('Failed to open output stream.', 102);
        }
        
        // open the new uploaded chunk
        if (!$in = @fopen($this->file->getPathname(), 'rb')) {
            @fclose($out);
            throw new ChunkSaveException('Failed to open input stream', 101);
        }
        
        // read and write in buffs
        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }
        
        // close the readers
        @fclose($out);
        @fclose($in);
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
