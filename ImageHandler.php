<?php

namespace mrssoft\image;

use yii\base\Exception;

/**
 * Image handler
 *
 * @author Melnikov R.S.
 *
 * Based on CImageHandler from https://github.com/tokolist/yii-components
 */
class ImageHandler extends \yii\base\Component
{
    private $originalImage;
    private $image;

    private $format = 0;

    private $width = 0;
    private $height = 0;

    private $mimeType = '';

    private $fileName = '';

    public $transparencyColor = [0, 0, 0];

    public const IMG_GIF = 1;
    public const IMG_JPEG = 2;
    public const IMG_PNG = 3;
    public const IMG_WEBP = 4;

    public const CORNER_LEFT_TOP = 1;
    public const CORNER_RIGHT_TOP = 2;
    public const CORNER_LEFT_BOTTOM = 3;
    public const CORNER_RIGHT_BOTTOM = 4;
    public const CORNER_CENTER = 5;

    public const FLIP_HORIZONTAL = 1;
    public const FLIP_VERTICAL = 2;
    public const FLIP_BOTH = 3;

    /**
     * @return resource
     */
    public function getImage()
    {
        return $this->image;
    }

    public function getFormat(): int
    {
        return $this->format;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function __destruct()
    {
        $this->freeImage();
    }

    private function freeImage()
    {
        if ($this->image) {
            imagedestroy($this->image);
        }

        if ($this->originalImage !== null) {
            if ($this->originalImage['image']) {
                imagedestroy($this->originalImage['image']);
            }
            $this->originalImage = null;
        }
    }

    private function checkLoaded()
    {
        if ($this->image === null) {
            throw new Exception('Load image first.');
        }
    }

    /**
     * Resize very large image with Imagick
     */
    public function resizeLarge(string $file_name, int $maxSize = 2000)
    {
        if (!extension_loaded('imagick')) {
            return;
        }

        $file_name = realpath($file_name);

        $im = new \Imagick();
        try {
            $im->pingImage($file_name);
        } catch (\ImagickException $e) {
            throw new Exception('Invalid or corrupted image file, please try uploading another image: ' . $e->getMessage());
        }

        $width = $im->getImageWidth();
        $height = $im->getImageHeight();
        if ($width > $maxSize || $height > $maxSize) {
            try {

                $fitbyWidth = ($maxSize / $width) < ($maxSize / $height);
                $im->readImage($file_name);

                if (defined('\Imagick::ALPHACHANNEL_FLATTEN')) {
                    $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_FLATTEN);
                    $im->setImageBackgroundColor('white');
                    if ($fitbyWidth) {
                        $im->resizeImage($maxSize, 0, \Imagick::FILTER_LANCZOS, 1);
                    } else {
                        $im->resizeImage(0, $maxSize, \Imagick::FILTER_LANCZOS, 1);
                    }
                } else if ($fitbyWidth) {
                    $im->thumbnailImage($maxSize, 0);
                } else {
                    $im->thumbnailImage(0, $maxSize);
                }

                $im->writeImage();
            } catch (\ImagickException $e) {
                header('HTTP/1.1 500 Internal Server Error');
                throw new Exception('An error occured reszing the image. ' . $e->getMessage());
            }
        } else if ($im->getImageFormat() === 'PNG') {
            $im->readImage($file_name);
            $im->writeImage();
        }

        $im->destroy();
    }

    /**
     * Flatten image
     */
    public function flatten(): self
    {
        $newImage = imagecreatetruecolor($this->width, $this->height);
        $white = imagecolorallocate($newImage, 255, 255, 255);
        imagefill($newImage, 0, 0, $white);

        imagecopyresampled($newImage, $this->image, 0, 0, 0, 0, $this->width, $this->height, $this->width, $this->height);

        $this->format = self::IMG_JPEG;
        $this->image = $newImage;
        return $this;
    }

    /**
     * Optimize file
     * @param array $params ['-progressive', '-copy none', '-optimize']
     */
    public function optimize(string $filename = null, array $params = ['-copy none', '-optimize']): bool
    {
        if ($filename === null) {
            $filename = $this->fileName;
            $params = $this->width > 200 || $this->height > 200 ? ['-progressive', '-copy none', '-optimize'] :
                ['-copy none', '-optimize'];
        }

        return match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => (new OptimizeJpg())->run($filename, $params),
            default => true,
        };
    }

    private function loadImage(string $file): array
    {
        $result = [];

        $this->resizeLarge($file);

        if ($imageInfo = @getimagesize($file)) {
            [$result['width'], $result['height']] = $imageInfo;

            $result['mimeType'] = $imageInfo['mime'];

            switch ($result['format'] = $imageInfo[2]) {
                case self::IMG_GIF:
                    if ($result['image'] = imagecreatefromgif($file)) {
                        return $result;
                    }
                    throw new Exception('Invalid image gif format');
                case self::IMG_JPEG:
                    if ($result['image'] = imagecreatefromjpeg($file)) {
                        return $result;
                    }
                    throw new Exception('Invalid image jpeg format');
                case self::IMG_PNG:
                    if ($result['image'] = imagecreatefrompng($file)) {
                        return $result;
                    }
                    throw new Exception('Invalid image png format');
                case self::IMG_WEBP:
                    if ($result['image'] = imagecreatefromwebp($file)) {
                        return $result;
                    }
                    throw new Exception('Invalid image webp format');
                default:
                    throw new Exception('Not supported image format');
            }
        } else {
            throw new Exception('Invalid image file');
        }
    }

    protected function initImage($image = false)
    {
        if ($image === false) {
            $image = $this->originalImage;
        }

        $this->width = $image['width'];
        $this->height = $image['height'];
        $this->mimeType = $image['mimeType'];
        $this->format = $image['format'];

        //Image
        if ($this->image) {
            imagedestroy($this->image);
        }

        $this->image = imagecreatetruecolor($this->width, $this->height);
        $this->preserveTransparency($this->image);
        imagecopy($this->image, $image['image'], 0, 0, 0, 0, $this->width, $this->height);
    }

    public function load(string $file): ?self
    {
        $this->freeImage();

        if ($this->originalImage = $this->loadImage($file)) {
            $this->initImage();
            $this->fileName = $file;
            return $this;
        }

        return null;
    }

    public function reload(): self
    {
        $this->checkLoaded();
        $this->initImage();

        return $this;
    }

    private function preserveTransparency($newImage)
    {
        switch ($this->format) {
            case self::IMG_GIF:
                $color = imagecolorallocate($newImage, $this->transparencyColor[0], $this->transparencyColor[1], $this->transparencyColor[2]);

                imagecolortransparent($newImage, $color);
                imagetruecolortopalette($newImage, false, 256);
                break;
            case self::IMG_PNG:
                imagealphablending($newImage, false);

                $color = imagecolorallocatealpha($newImage, $this->transparencyColor[0], $this->transparencyColor[1], $this->transparencyColor[2], 0);

                imagefill($newImage, 0, 0, $color);
                imagesavealpha($newImage, true);
                break;
        }
    }

    public function resize($toWidth, $toHeight, bool $proportional = true): self
    {
        $this->checkLoaded();

        $toWidth = $toWidth !== false ? $toWidth : $this->width;
        $toHeight = $toHeight !== false ? $toHeight : $this->height;

        if ($proportional) {
            $newHeight = $toHeight;
            $newWidth = round($newHeight / $this->height * $this->width);


            if ($newWidth > $toWidth) {
                $newWidth = $toWidth;
                $newHeight = round($newWidth / $this->width * $this->height);
            }
        } else {
            $newWidth = $toWidth;
            $newHeight = $toHeight;
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        $this->preserveTransparency($newImage);

        imagecopyresampled($newImage, $this->image, 0, 0, 0, 0, $newWidth, $newHeight, $this->width, $this->height);


        imagedestroy($this->image);

        $this->image = $newImage;
        $this->width = $newWidth;
        $this->height = $newHeight;

        return $this;
    }

    public function thumb($toWidth, $toHeight, bool $proportional = true): self
    {
        $this->checkLoaded();

        if ($toWidth !== false) {
            $toWidth = min($toWidth, $this->width);
        }

        if ($toHeight !== false) {
            $toHeight = min($toHeight, $this->height);
        }

        $this->resize($toWidth, $toHeight, $proportional);

        return $this;
    }

    public function trimImage($color = 0xFFFFFF, int $border = 0, int $diff = 0): self
    {
        //find the size of the borders
        $b_top = 0;
        $b_btm = 0;
        $b_lft = 0;
        $b_rt = 0;

        $filterColor = [
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF
        ];

        //top
        for (; $b_top < $this->height; ++$b_top) {
            for ($x = 0; $x < $this->width; ++$x) {
                if ($this->compareColors(imagecolorat($this->image, $x, $b_top), $filterColor, $diff) === false) {
                    break 2;
                }
            }
        }
        //bottom
        for (; $b_btm < $this->height; ++$b_btm) {
            for ($x = 0; $x < $this->width; ++$x) {
                if ($this->compareColors(imagecolorat($this->image, $x, $this->height - $b_btm - 1), $filterColor, $diff) === false) {
                    break 2;
                }
            }
        }
        //left
        for (; $b_lft < $this->width; ++$b_lft) {
            for ($y = 0; $y < $this->height; ++$y) {
                if ($this->compareColors(imagecolorat($this->image, $b_lft, $y), $filterColor, $diff) === false) {
                    break 2;
                }
            }
        }
        //right
        for (; $b_rt < $this->width; ++$b_rt) {
            for ($y = 0; $y < $this->height; ++$y) {
                if ($this->compareColors(imagecolorat($this->image, $this->width - $b_rt - 1, $y), $filterColor, $diff) === false) {
                    break 2;
                }
            }
        }

        if ($b_lft == 0 && $b_rt == 0 && $b_top == 0 && $b_btm == 0) {
            return $this;
        }

        if ($border > 0) {
            $b_lft -= $border;
            $b_rt -= $border;
            $b_top -= $border;
            $b_btm -= $border;
            $b_lft = max($b_lft, 0);
            $b_rt = max($b_rt, 0);
            $b_top = max($b_top, 0);
            $b_btm = max($b_btm, 0);
        }

        //copy the contents, excluding the border
        $newImage = imagecreatetruecolor($this->width - ($b_lft + $b_rt), $this->height - ($b_top + $b_btm));

        $this->width = imagesx($newImage);
        $this->height = imagesy($newImage);

        imagecopy($newImage, $this->image, 0, 0, $b_lft, $b_top, $this->width, $this->height);

        $this->image = $newImage;
        return $this;
    }

    private function compareColors(int $imageColor, array $filter = [255, 255, 255], int $diff = 0): bool
    {
        $r2 = ($imageColor >> 16) & 0xFF;
        $g2 = ($imageColor >> 8) & 0xFF;
        $b2 = $imageColor & 0xFF;

        return abs($filter[0] - $r2) + abs($filter[1] - $g2) + abs($filter[2] - $b2) <= $diff;
    }

    public function watermark(string $watermarkFile, int $offsetX, int $offsetY, int $corner = self::CORNER_RIGHT_BOTTOM, bool $zoom = false): ?self
    {
        $this->checkLoaded();

        if ($wImg = $this->loadImage($watermarkFile)) {

            $watermarkWidth = $wImg['width'];
            $watermarkHeight = $wImg['height'];

            if ($zoom !== false) {
                $dimension = round(max($this->width, $this->height) * $zoom);

                $watermarkHeight = $dimension;
                $watermarkWidth = round($watermarkHeight / $wImg['height'] * $wImg['width']);

                if ($watermarkWidth > $dimension) {
                    $watermarkWidth = $dimension;
                    $watermarkHeight = round($watermarkWidth / $wImg['width'] * $wImg['height']);
                }
            }

            [$posX, $posY] = $this->getPositionByCorner($corner, $offsetX, $offsetY, $watermarkWidth, $watermarkHeight);

            imagecopyresampled($this->image, $wImg['image'], $posX, $posY, 0, 0, $watermarkWidth, $watermarkHeight, $wImg['width'], $wImg['height']);
            imagedestroy($wImg['image']);

            return $this;
        }

        return null;
    }

    /**
     * @param int $mode
     * @return $this
     * @throws Exception
     */
    public function flip(int $mode): self
    {
        $this->checkLoaded();

        $srcX = 0;
        $srcY = 0;
        $srcWidth = $this->width;
        $srcHeight = $this->height;

        switch ($mode) {
            case self::FLIP_HORIZONTAL:
                $srcX = $this->width - 1;
                $srcWidth = -$this->width;
                break;
            case self::FLIP_VERTICAL:
                $srcY = $this->height - 1;
                $srcHeight = -$this->height;
                break;
            case self::FLIP_BOTH:
                $srcX = $this->width - 1;
                $srcY = $this->height - 1;
                $srcWidth = -$this->width;
                $srcHeight = -$this->height;
                break;
            default:
                throw new Exception('Invalid $mode value');
        }

        $newImage = imagecreatetruecolor($this->width, $this->height);
        $this->preserveTransparency($newImage);

        imagecopyresampled($newImage, $this->image, 0, 0, $srcX, $srcY, $this->width, $this->height, $srcWidth, $srcHeight);

        imagedestroy($this->image);

        $this->image = $newImage;

        //dimensions not changed

        return $this;
    }

    /**
     * @param $degrees
     * @return $this
     * @throws \yii\base\Exception
     */
    public function rotate($degrees): self
    {
        $this->checkLoaded();

        $degrees = (int)$degrees;
        $this->image = imagerotate($this->image, $degrees, 0);

        $this->width = imagesx($this->image);
        $this->height = imagesy($this->image);

        return $this;
    }

    /**
     * @param $width
     * @param $height
     * @param int|null $startX
     * @param int|null $startY
     * @return $this
     * @throws Exception
     */
    public function crop($width, $height, ?int $startX = null, ?int $startY = null): self
    {
        $this->checkLoaded();

        $width = (int)$width;
        $height = (int)$height;

        //Centered crop
        $startX = $startX ?? (int)floor(($this->width - $width) / 2);
        $startY = $startY ?? (int)floor(($this->height - $height) / 2);

        //Check dimensions
        $startX = max(0, min($this->width, $startX));
        $startY = max(0, min($this->height, $startY));
        $width = min($width, $this->width - $startX);
        $height = min($height, $this->height - $startY);


        $newImage = imagecreatetruecolor($width, $height);

        $this->preserveTransparency($newImage);

        imagecopyresampled($newImage, $this->image, 0, 0, $startX, $startY, $width, $height, $width, $height);

        imagedestroy($this->image);

        $this->image = $newImage;
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /**
     * @param string $text
     * @param string $fontFile
     * @param int $size
     * @param array $color
     * @param int $corner
     * @param int $offsetX
     * @param int $offsetY
     * @param int $angle
     * @return $this
     * @throws Exception
     */
    public function text(string $text, string $fontFile, int $size = 12, array $color = [0, 0, 0], int $corner = self::CORNER_LEFT_TOP, int $offsetX = 0, int $offsetY = 0, int $angle = 0): self
    {
        $this->checkLoaded();

        $bBox = imagettfbbox($size, $angle, $fontFile, $text);
        $textHeight = $bBox[1] - $bBox[7];
        $textWidth = $bBox[2] - $bBox[0];

        $color = imagecolorallocate($this->image, $color[0], $color[1], $color[2]);

        [$posX, $posY] = $this->getPositionByCorner($corner, $offsetX, $offsetY, $textWidth, $textHeight);

        imagettftext($this->image, $size, $angle, $posX, $posY + $textHeight, $color, $fontFile, $text);

        return $this;
    }

    /**
     * @param $width
     * @param $height
     * @return $this
     * @throws \yii\base\Exception
     */
    public function adaptiveThumb($width, $height): self
    {
        $this->checkLoaded();

        $width = (int)$width;
        $height = (int)$height;

        $widthProportion = $width / $this->width;
        $heightProportion = $height / $this->height;

        if ($widthProportion > $heightProportion) {
            $newWidth = $width;
            $newHeight = round($newWidth / $this->width * $this->height);
        } else {
            $newHeight = $height;
            $newWidth = round($newHeight / $this->height * $this->width);
        }

        $this->resize($newWidth, $newHeight);

        $this->crop($width, $height);

        return $this;
    }

    /**
     * @param $toWidth
     * @param $toHeight
     * @param array $backgroundColor
     * @return $this
     * @throws \yii\base\Exception
     */
    public function resizeCanvas($toWidth, $toHeight, array $backgroundColor = [255, 255, 255]): self
    {
        $this->checkLoaded();

        $newWidth = min($toWidth, $this->width);
        $newHeight = min($toHeight, $this->height);

        $widthProportion = $newWidth / $this->width;
        $heightProportion = $newHeight / $this->height;

        if ($widthProportion < $heightProportion) {
            $newHeight = round($widthProportion * $this->height);
        } else {
            $newWidth = round($heightProportion * $this->width);
        }

        $posX = floor(($toWidth - $newWidth) / 2);
        $posY = floor(($toHeight - $newHeight) / 2);


        $newImage = imagecreatetruecolor($toWidth, $toHeight);

        $backgroundColor = imagecolorallocate($newImage, $backgroundColor[0], $backgroundColor[1], $backgroundColor[2]);
        imagefill($newImage, 0, 0, $backgroundColor);

        imagecopyresampled($newImage, $this->image, $posX, $posY, 0, 0, $newWidth, $newHeight, $this->width, $this->height);

        imagedestroy($this->image);

        $this->image = $newImage;
        $this->width = $toWidth;
        $this->height = $toHeight;

        return $this;
    }

    /**
     * @return $this
     */
    public function grayscale(): self
    {
        $newImage = imagecreatetruecolor($this->width, $this->height);

        imagecopy($newImage, $this->image, 0, 0, 0, 0, $this->width, $this->height);
        imagecopymergegray($newImage, $newImage, 0, 0, 0, 0, $this->width, $this->height, 0);

        imagedestroy($this->image);

        $this->image = $newImage;

        return $this;
    }

    public function show(bool $inFormat = false, int $jpegQuality = 75): self
    {
        $this->checkLoaded();

        if (!$inFormat) {
            $inFormat = $this->format;
        }

        switch ($inFormat) {
            case self::IMG_GIF:
                header('Content-type: image/gif');
                imagegif($this->image);
                break;
            case self::IMG_JPEG:
                header('Content-type: image/jpeg');
                imagejpeg($this->image, null, $jpegQuality);
                break;
            case self::IMG_PNG:
                header('Content-type: image/png');
                imagepng($this->image);
                break;
            case self::IMG_WEBP:
                header('Content-type: image/webp');
                imagewebp($this->image);
                break;
            default:
                throw new Exception('Invalid image format for output');
        }

        return $this;
    }

    public function save(bool|string $file = false, bool|int $toFormat = false, int $quality = 80): self
    {
        if (empty($file)) {
            $file = $this->fileName;
        }

        $this->checkLoaded();

        if (!$toFormat) {
            $toFormat = $this->format;
        }

        switch ($toFormat) {
            case self::IMG_GIF:
                if (!imagegif($this->image, $file)) {
                    throw new Exception('Can\'t save gif file');
                }
                break;
            case self::IMG_JPEG:
                if (!imagejpeg($this->image, $file, $quality)) {
                    throw new Exception('Can\'t save jpeg file');
                }
                break;
            case self::IMG_PNG:
                if (!imagepng($this->image, $file)) {
                    throw new Exception('Can\'t save png file');
                }
                break;
            case self::IMG_WEBP:
                if (!imagewebp($this->image, $file, $quality)) {
                    throw new Exception('Can\'t save webp file');
                }
                break;
            default:
                throw new Exception('Invalid image format for save');
        }

        return $this;
    }

    /**
     * @param int $corner
     * @param int $offsetX
     * @param int $offsetY
     * @param mixed $watermarkWidth
     * @param mixed $watermarkHeight
     * @return array
     * @throws Exception
     */
    private function getPositionByCorner(int $corner, int $offsetX, int $offsetY, mixed $watermarkWidth, mixed $watermarkHeight): array
    {
        switch ($corner) {
            case self::CORNER_LEFT_TOP:
                $posX = $offsetX;
                $posY = $offsetY;
                break;
            case self::CORNER_RIGHT_TOP:
                $posX = $this->width - $watermarkWidth - $offsetX;
                $posY = $offsetY;
                break;
            case self::CORNER_LEFT_BOTTOM:
                $posX = $offsetX;
                $posY = $this->height - $watermarkHeight - $offsetY;
                break;
            case self::CORNER_RIGHT_BOTTOM:
                $posX = $this->width - $watermarkWidth - $offsetX;
                $posY = $this->height - $watermarkHeight - $offsetY;
                break;
            case self::CORNER_CENTER:
                $posX = floor(($this->width - $watermarkWidth) / 2);
                $posY = floor(($this->height - $watermarkHeight) / 2);
                break;
            default:
                throw new Exception('Invalid $corner value');
        }
        return array($posX, $posY);
    }
}