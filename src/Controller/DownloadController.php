<?php

namespace App\Controller;

use App\Entity\Image;
use App\Utils\IIIFUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class DownloadController extends AbstractController
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    #[Route("/{_locale}/download", name: "download")]
    public function download(Request $request)
    {
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $image = $request->get('image');
        $type = exif_imagetype($image);
        $mimes  = array(
            IMAGETYPE_JPEG => "jpg",
            IMAGETYPE_PNG => "png",
            IMAGETYPE_PSD => "psd",
            IMAGETYPE_BMP => "bmp",
            IMAGETYPE_TIFF_II => "tif",
            IMAGETYPE_TIFF_MM => "tif",
            IMAGETYPE_JPC => "jpc",
            IMAGETYPE_JP2 => "jp2",
            IMAGETYPE_JPX => "jpx",
            IMAGETYPE_JB2 => "jb2",
            IMAGETYPE_SWC => "swc",
            IMAGETYPE_IFF => "iff",
            IMAGETYPE_WBMP => "wbmp",
            IMAGETYPE_XBM => "xbm",
            IMAGETYPE_ICO => "ico"
        );

        if(!array_key_exists($type, $mimes)) {
            $response = new Response(json_encode(array('hash' => null)));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        } else {
            stream_context_set_default(array('http' => array('method' => 'HEAD')));
            $headers = get_headers($image, 1);
            stream_context_set_default(array('http' => array('method' => 'GET')));

            if (!isset($headers["Content-Length"])) {
                $response = new Response(json_encode(array('hash' => null, 'error' => $this->translator->trans('Unable to retrieve file from URL'))));
                $response->headers->set('Content-Type', 'application/json');
                return $response;
            } else {
                $fileSize = $headers["Content-Length"];
                if($fileSize >= 10000000) {
                    $response = new Response(json_encode(array('hash' => null, 'error' => $this->translator->trans('File is too big') . ' (' . $this->formatBytes($fileSize) . ')')));
                    $response->headers->set('Content-Type', 'application/json');
                    return $response;
                } else {
                    $extension = $mimes[$type];
                    $folder = 'annotation_images';
                    $filenameNoExt = round(microtime(true) * 1000);
                    $filename = $folder . '/' . $filenameNoExt . '.' . $extension;
                    $thumbnail = $folder . '/' . $filenameNoExt . '_thm.jpg';
                    copy($image, $filename);

                    $thumbnail = IIIFUtil::generateThumbnail($filename, $thumbnail);

                    $image = new Image();
                    $image->setImage('/' . $filename);
                    $image->setThumbnail('/' . $thumbnail);
                    $em = $this->container->get('doctrine')->getManager();
                    $em->persist($image);
                    $em->flush();

                    $response = new Response(json_encode(array('hash' => $image->getHash(), 'image' => '/' . $filename, 'thumbnail' => '/' . $thumbnail)));
                    $response->headers->set('Content-Type', 'application/json');
                    return $response;
                }
            }
        }
    }

    private function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('', 'KB', 'MB', 'GB', 'TB');
        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }
}
