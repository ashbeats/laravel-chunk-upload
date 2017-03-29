<?php
namespace Pion\Laravel\ChunkUpload\Handler;

use Illuminate\Http\Request;
use storage;
use Pion\Laravel\ChunkUpload\Storage\ChunkStorage;

/***
 * Class ResumablejsParallelUploadHandler
 *
 * - wait for all chunks.
 *
 * - added by Ash.
 *
 * @package Pion\Laravel\ChunkUpload\Handler
 */
class ResumablejsParallelUploadHandler extends ChunksInRequestUploadHandler
{
    const CHUNK_NUMBER_INDEX = 'resumableChunkNumber';
    const TOTAL_CHUNKS_INDEX = 'resumableTotalChunks';
    
    /**
     * AbstractReceiver constructor.
     *
     * @param Request $request
     * @param UploadedFile $file
     * @param AbstractConfig $config
     */
    public function __construct(Request $request, $file, $config)
    {
        parent::__construct($request, $file, $config);
        
        $this->currentChunk = $this->getCurrentChunkFromRequest($request);
        $this->chunksTotal = $this->getTotalChunksFromRequest($request);
    }
    
    /**
     * Returns current chunk from the request
     *
     * @param Request $request
     *
     * @return int
     */
    protected function getCurrentChunkFromRequest(Request $request)
    {
        return $request->get(self::CHUNK_NUMBER_INDEX);
    }
    
    /**
     * Returns current chunk from the request
     *
     * @param Request $request
     *
     * @return int
     */
    protected function getTotalChunksFromRequest(Request $request)
    {
        return $request->get(self::TOTAL_CHUNKS_INDEX);
    }
    
    /**
     * Checks if the current abstract handler can be used via HandlerFactory
     *
     * @param Request $request
     *
     * @return bool
     */
    public static function canBeUsedForRequest(Request $request)
    {
        return $request->has(self::CHUNK_NUMBER_INDEX) && $request->has(self::TOTAL_CHUNKS_INDEX);
    }
    
    /**
     * Returns the first chunk
     *
     * @return bool
     */
    public function isFirstChunk()
    {
        return $this->currentChunk == 1;
    }
    
    /**
     * Returns the chunks count
     * too - rename to allChunksArrived();
     * @return int
     */
    public function isLastChunk()
    {
        return $this->haveAllChunksArrived();
        //dd($chunkFilename, $x->files(), $matches, $unique_file_prefix);
    
        //return false;
        // the bytes starts from zero, remove 1 byte from total
        //return $this->currentChunk == $this->chunksTotal;
    }
    
    /**
     * Returns the chunks count
     * too - rename to allChunksArrived();
     * @return int
     */
    public function haveAllChunksArrived()
    {
        return $this->countChucksReceived() == $this->chunksTotal;
        //dd($chunkFilename, $x->files(), $matches, $unique_file_prefix);
    
        //return false;
        // the bytes starts from zero, remove 1 byte from total
        //return $this->currentChunk == $this->chunksTotal;
    }
    
    public function countChucksReceived()
    {
        $count = count($this->getAllSections());
        //dump($count . " sections available of " . $this->chunksTotal);
        return $count;
    }
    
    public function extractUniqueFilePrefix($subject)
    {
        // Extract the root-filename:
        if (preg_match('%([^/\\\\]+)--section-([\d]+)\.part%im', $subject, $regs)) {
            return $regs[1];
        }else{
            throw new Exception("unique_file_prefix missing");
        }
    
        
    }
    
    public function extractSectionIndex($subject)
    {
        // Extract the real section/chunk #
        if (preg_match('%--section-([\d]+)\.part%im', $subject, $regs)) {
            return $regs[1];
        }else{
            throw new Exception("section index missing");
        }
        
        
    }
    
    
    public function getAllSections()
    {
    
        $unique_file_prefix =  $this->extractUniqueFilePrefix($this->getChunkFileName());
        
        /*** check if parts have arrived. */
        $x = new ChunkStorage(Storage::disk($this->config->chunksDiskName()), $this->config);
       
        $matches = preg_grep('/' . preg_quote($unique_file_prefix) .'--section-([\d]+)\.part/im', $x->files());
        //dump('new', $matches);
        
       /* //dump($matches);
        dd($x);
        // loop through each file to see which is not locked.
        $folder = realpath($x->directory());
        
        foreach($matches as $match){
            
            dump("reading $folder . $match");
            $fp = fopen($match, 'w');
            
            if (!flock($fp, LOCK_EX|LOCK_NB, $wouldblock)) {
                if ($wouldblock) {
                    // another process holds the lock
                    dump('another process holds the lock');
                }
                else {
                    // couldn't lock for another reason, e.g. no such file
                    dump("// couldn't lock for another reason, e.g. no such file");
                }
            }
            else {
                // lock obtained
                dump('lock obtained');
            }
    
            @fclose($fp);
    
        }
        
        */
        
        
        return $matches;
    }
    
    /**
     * Returns the current chunk index
     *
     * @return bool
     */
    public function isChunkedUpload()
    {
        return $this->chunksTotal > 1;
    }
    
    /**
     * Returns the chunk file name. Uses the original client name and the total bytes
     *
     * @return string returns the original name with the part extension
     *
     * @see createChunkFileName()
     */
    public function getChunkFileName()
    {
        return $this->createChunkFileName("-section-{$this->currentChunk}");
    }
    
    /**
     * Builds the chunk file name per session and the original name. You can
     * provide custom additional name at the end of the generated file name. All chunk
     * files has .part extension
     *
     * @param string|null $additionalName
     *
     * @return string
     *
     * @see UploadedFile::getClientOriginalName()
     * @see Session::getId()
     */
    protected function createChunkFileName($additionalName = null)
    {
        
        //dd("Hey Ash!");
        
        // prepare basic name structure
        $array = [
            $this->file->getClientOriginalName()
        ];
        
        // ensure that the chunk name is for unique for the client session
        
        // the session needs more config on the provider
        if ($this->config->chunkUseSessionForName()) {
            $array[] = Session::getId();
        }
        
        // can work without any additional setup
        if ($this->config->chunkUseBrowserInfoForName()) {
            $array[] = md5($this->request->ip() . $this->request->header("User-Agent", "no-browser"));
        }
        
        // add
        if (!is_null($additionalName)) {
            $array[] = $additionalName;
        }
        
        //$x = new ChunkStorage;
        
      
        
        // build name
        return implode("-", $array) . "." . ChunkStorage::CHUNK_EXTENSION;
    }
    
    /**
     * @return int
     */
    public function getTotalChunks()
    {
        return $this->chunksTotal;
    }
    
    /**
     * @return int
     */
    public function getCurrentChunk()
    {
        return $this->currentChunk;
    }
    
    /**
     * Returns the percentage of the uploaded file
     *
     * @return int
     */
    public function getPercentageDone()
    {
        // todo - rework this.
        return ceil($this->countChucksReceived() / $this->chunksTotal * 100);
        //return ceil($this->currentChunk / $this->chunksTotal * 100);
    }
}