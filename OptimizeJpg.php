<?php

namespace mrssoft\image;

/**
 * Optimize JPG
 * Class OptimizeJpg
 * @package mrssoft\image
 */
class OptimizeJpg extends \yii\base\Component
{
    /**
     * @param string $filename
     * @param array $params ['-progressive', '-copy none', '-optimize']
     * @return bool
     */
    public function run(string $filename, array $params = ['-copy none', '-optimize']): bool
    {
        $programm = 'jpegtran';
        if (stripos(PHP_OS, 'WIN') === 0) {
            $programm = 'C:\jpegtran\jpegtran.exe';
        }

        $strParams = implode(' ', $params);
        $cmd = "$programm $strParams -outfile $filename $filename";
        @exec($cmd, $output, $ret);

        return $ret == 0;
    }
}