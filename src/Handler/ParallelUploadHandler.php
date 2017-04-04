<?php
namespace Pion\Laravel\ChunkUpload\Handler;

use Storage;
use File;
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
class ParallelUploadHandler extends ResumableJSUploadHandler //ChunksInRequestUploadHandler
{
    
    /**
     * Returns the chunks count
     *
     * @return int
     * @alias $this->haveAllChunksArrived()
     */
    public function isLastChunk()
    {
        return $this->haveAllChunksArrived();
    }
    
    /**
     * Returns the chunks count
     * too - rename to allChunksArrived();
     *
     * @return int
     */
    public function haveAllChunksArrived()
    {
        return $this->countChucksReceived() == $this->chunksTotal;
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
        } else {
            throw new Exception("unique_file_prefix missing");
        }
        
        
    }
    
    public function extractSectionIndex($subject)
    {
        // Extract the real section/chunk #
        if (preg_match('%--section-([\d]+)\.part%im', $subject, $regs)) {
            return $regs[1];
        } else {
            throw new Exception("section index missing");
        }
        
        
    }
    
    
    public function getAllSections()
    {
        $unique_file_prefix = $this->extractUniqueFilePrefix($this->getChunkFileName());
        $x = new ChunkStorage(Storage::disk($this->config->chunksDiskName()), $this->config);

        $matches = preg_grep('/' . preg_quote($unique_file_prefix) . '--section-([\d]+)\.part/im', $x->files());
        
        return $matches;
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
     * Returns the percentage of the uploaded file
     *
     * @return int
     */
    public function getPercentageDone()
    {
        return ceil($this->countChucksReceived() / $this->chunksTotal * 100);
    }
}