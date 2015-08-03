<?php

/**
 * Handle with downloads
 *  
 * @example download('default.pdf'); download file as attachment
 * @example download('default.pdf',null,true); download file inline
 *
 * @param string $filename
 * @param string $directory
 * @param boolean $inline
 */
function download($filename, $directory = null, $inline = false) {

    /**
     * Disable Compressed Output
     */
    @ini_set('zlib.output_compression', 'Off');
    @ini_set('max_execution_time', 0);

    /**
     * Disable gzhandler Output Handler 
     */
    $outputHandler = ob_list_handlers();
    $found = false;
    foreach ($outputHandler as $handler) {
        if ('ob_gzhandler' == $handler || $found) {
            @ob_end_clean();
            $found = true;
        }
    }

    /**
     * Protocol
     */
    $protocol = isset($_SERVER["SERVER_PROTOCOL"]) ? $_SERVER["SERVER_PROTOCOL"] : '';
    if ('HTTP/1.1' != $protocol && 'HTTP/1.0' != $protocol) {
        $protocol = 'HTTP/1.0';
    }

    $phpInterface = substr(php_sapi_name(), 0, 3);
    if (in_array($phpInterface, array('cgi', 'fpm'))) {
        $protocol = 'Status:';
    }

    if (is_null($directory)) {
        $directory = dirname(__FILE__);
    } else {
        $directory = realpath(rtrim(str_replace('\\', '/', $directory), "/"));
        if (!is_dir($directory)) {
            header($protocol . ' 500 Internal Server Error', true, 500);
            exit;
        }
    }

    /**
     * File
     */
    $fileName = basename($filename);
    $filePath = $directory . '/' . $fileName;

    if (!file_exists($filePath)) {
        header($protocol . ' 404 Not Found', true, 404);
        exit;
    }

    if (!is_readable($filePath)) {
        header($protocol . ' 403 Forbidden', true, 403);
        exit;
    }

    $stat = stat($filePath);
    $fileSize = $stat['size'];

    /**
     * ETag and Last-Modified Headers
     */
    $eTag = sprintf('%x-%x', $stat['ino'], $stat['mtime'] * 1000000);

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $eTag) {
        header('ETag: "' . $eTag . '"');
        header($protocol . ' 304 Not Modified', true, 304);
        exit;
    } elseif (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && strtotime((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $stat['mtime']) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT');
        header($protocol . ' 304 Not Modified', true, 304);
        exit;
    }

    /**
     * Open File
     */
    if (false === ($file = @fopen($filePath, 'r'))) {
        header($protocol . ' 500 Internal Server Error', true, 500);
        exit;
    }

    /**
     * Parcial Content
     */
    $partialContent = false;
    $ranges = false;
    $multiRanges = false;
    $multiRangesCount = 0;

    if (isset($_SERVER['HTTP_RANGE']) && false !== ($range = stristr(trim($_SERVER['HTTP_RANGE']), 'bytes='))) {
        $range = substr($range, 6);
        $boundary = 'D6F92E31D2C135AB';
        $ranges = explode(',', $range);

        if (isset($_SERVER['IF_RANGE']) && $_SERVER['IF_RANGE'] != $eTag) {
            $ranges = false;
        }
    }

    /**
     * Validate Ranges
     */
    if (is_array($ranges) && !empty($ranges)) {
        $partialContent = true;
        $multiRangesCount = count($ranges);
        $multiRanges = $multiRangesCount > 1 ? true : false;

        foreach ($ranges as $index => $range) {
            if (false !== strpos($range, '-')) {
                $aTemp = explode('-', $range);
                $atTemp = array_map('trim', $aTemp);
                if (count($atTemp) === 2) {
                    list($first, $last) = $atTemp;
                    if ('' === $first && is_numeric($last)) {
                        $first = $fileSize - intval($last, 10);
                        if ($first < 0) {
                            $first = 0;
                        }
                        $last = $fileSize - 1;
                        $ranges[$index] = array($first, $last);
                    } elseif (is_numeric($first) && '' === $last) {
                        $first = intval($first, 10);
                        $last = $fileSize - 1;
                        if ($first > $last) {
                            unset($ranges[$index]);
                        } else {
                            $ranges[$index] = array($first, $last);
                        }
                    } elseif (is_numeric($first) && is_numeric($last)) {
                        $first = intval($first, 10);
                        $last = intval($last, 10);
                        if ($first > $last) {
                            unset($ranges[$index]);
                        } else {
                            $ranges[$index] = array($first, $last);
                        }
                    } else {
                        unset($ranges[$index]);
                    }
                } else {
                    unset($ranges[$index]);
                }
            } else {
                unset($ranges[$index]);
            }
        }

        if ($multiRangesCount !== count($ranges)) {
            header($protocol . ' 416 Requested Range Not Satisfiable', true, 416);
            header('Accept-Ranges: bytes');
            header('Content-Range: */' . $fileSize);
            exit;
        }
    }

    /**
     * Common Headers
     */
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 1728000) . ' GMT');
    header('Cache-Control: private, -age=1728000');

    header('Accept-Ranges: bytes');
    header('Content-Transfer-Encoding: binary');

    if (true === $inline) {
        header('Content-Disposition: inline; filename="' . $fileName . '"');
    } else {
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
    }

    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stat['mtime']) . ' GMT');
    header('ETag: "' . $eTag . '"');

    $bufferSize = 1024 * 8;
    $contentType = mime_content_type($filePath);

    if (true === $partialContent) {
        header($protocol . ' 206 Partial Content', true, 206);
        if (true === $multiRanges) {
            /**
             * Multiple Range
             */
            $contentSize = 0;
            foreach ($ranges as $range) {
                list($first, $last) = $range;
                $contentSize += strlen("\r\n--$boundary\r\n");
                $contentSize += strlen("Content-Type: $contentType\r\n");
                $contentSize += strlen("Content-Range: bytes $first-$last/$fileSize\r\n\r\n");
                $contentSize += $last - $first + 1;
            }
            $contentSize += strlen("\r\n--$boundary--\r\n");

            header("Content-Length: $contentSize");
            header("Content-Type: multipart/x-byteranges; boundary=$boundary");

            /**
             * Output the content for multiple range
             */
            foreach ($ranges as $range) {
                list($first, $last) = $range;
                echo "\r\n--$boundary\r\n";
                echo "Content-Type: $contentType\r\n";
                echo "Content-Range: bytes $first-$last/$fileSize\r\n\r\n";
                if ($first > 0) {
                    fseek($file, $first);
                }

                $length = $last - $first + 1;
                ;
                $error = false;
                while ($length > 0 && false === $error) {
                    if ($length < $bufferSize) {
                        $bufferSize = $length;
                    }
                    if (false !== ($data = @fread($file, $bufferSize))) {
                        echo $data;
                        ob_flush();
                        flush();
                    } else {
                        $error = true;
                    }
                    $length -= $bufferSize;
                }
            }
            echo "\r\n--$boundary--\r\n";
            ob_flush();
            flush();
            @fclose($file);
            exit;
        } else {
            /**
             * Single Range
             */
            list($first, $last) = $ranges[0];
            $fileSize = $last - $first + 1;
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . $fileSize);
            header('Content-Range: bytes ' . $first . '-' . $last . '/' . $fileSize);
            if ($first > 0) {
                fseek($file, $first);
            }
        }
    } else {
        /**
         * No Parcial Content
         */
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
    }

    /**
     * Output the content for single range and no range at all
     */
    $length = $fileSize;
    $error = false;
    while ($length > 0 && false === $error) {
        if ($length < $bufferSize) {
            $bufferSize = $length;
        }
        if (false !== ($data = @fread($file, $bufferSize))) {
            echo $data;
            ob_flush();
            flush();
        } else {
            $error = true;
        }
        $length -= $bufferSize;
    }
    @fclose($file);
    exit;
}
