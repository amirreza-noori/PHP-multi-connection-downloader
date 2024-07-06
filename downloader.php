<?php

class ParallelDownloader
{
    private $connections;
    private $timeout;
    private $url;
    private $path;


    public function __construct($url, $path, $connections = 100, $timeout = 3600)
    {
        $this->url = $url;
        $this->path = $path;
        $this->connections = $connections;
        $this->timeout = $timeout;
    }


    private function init_range_download_handler($index, $range)
    {
        $file_pointer = fopen($this->path . '.part' . $index, 'w+');

        $handler = curl_init();
        curl_setopt($handler, CURLOPT_URL, $this->url);
        curl_setopt($handler, CURLOPT_RANGE, $range);
        curl_setopt($handler, CURLOPT_BINARYTRANSFER, 1);
        curl_setopt($handler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($handler, CURLOPT_FILE, $file_pointer);
        curl_setopt($handler, CURLOPT_TIMEOUT, $this->timeout);
        return ['handler' => $handler, 'range' => $range, 'attempts' => 0, 'file' => $file_pointer];
    }

    function start()
    {
        $multi_handle = curl_multi_init();
        $parts_info = [];

        $file_size = $this->fetch_file_size();
        $chunk_size = ceil($file_size / $this->connections);

        for ($i = 0; $i < $this->connections; $i++) {
            $start = $i * $chunk_size;
            $end = ($i + 1) * $chunk_size - 1;
            if ($i == $this->connections - 1) $end = $file_size - 1;
            $range = "{$start}-{$end}";

            $parts_info[$i] = $this->init_range_download_handler($i, $range);

            curl_multi_add_handle($multi_handle, $parts_info[$i]['handler']);
        }

        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle);
        } while ($running > 0);

        foreach ($parts_info as $part_info) {
            fclose($part_info['file']);
            curl_multi_remove_handle($multi_handle, $part_info['handler']);
            curl_close($part_info['handler']);
        }

        if ($file = fopen($this->path, 'w+')) {
            for ($i = 0; $i < $this->connections; $i++) {
                fwrite($file, file_get_contents($this->path . '.part' . $i));
                unlink($this->path . '.part' . $i);
            }
            fclose($file);
        }

        curl_multi_close($multi_handle);
    }


    private function fetch_file_size()
    {
        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_exec($ch);
        $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        return $file_size;
    }
}


$url = 'https://dowunload-url';
$path = 'file-path.zip';
$downloader = new ParallelDownloader($url, $path);
$downloader->start();
