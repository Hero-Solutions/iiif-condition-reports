<?php

namespace App\Controller;

use App\Entity\Image;
use App\Utils\IIIFUtil;
use Imagick;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class UploadController extends AbstractController
{
    /**
     * @Route("/{_locale}/upload", name="upload")
     */
    public function upload(Request $request)
    {
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $file = $request->files->get('annotate-file');
        if($file != null) {
            $extension = $file->getClientOriginalExtension();
            if ($extension == null) {
                $extension = 'jpg';
            }
            $folder = 'annotation_images';
            $filenameNoExt = round(microtime(true) * 1000);
            $filename = $folder . '/' . $filenameNoExt . '.' . $extension;

            $image = new Image();
            $thumbnail = $folder . '/' . $filenameNoExt . '_thm.jpg';
            $file->move($folder, $filenameNoExt . '.' . $extension);
            if($extension !== 'jpg') {
                $filename = IIIFUtil::convertToJpeg($filename, $folder, $filenameNoExt);
            }
            $image->setImage('/' . $filename);
            $thumbnail = IIIFUtil::generateThumbnail($filename, $thumbnail);
            $image->setThumbnail('/' . $thumbnail);

            $em = $this->container->get('doctrine')->getManager();
            $em->getConnection()->getConfiguration()->setSQLLogger(null);
            $em->persist($image);
            $em->flush();

            $response = new Response(json_encode(array('hash' => $image->getHash(), 'image' => $image->getImage(), 'thumbnail' => $image->getThumbnail())));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        } else {
            $file = $request->files->get('setup-plan-default-input');
            if($file == null) {
                $file = $request->files->get('setup-plan-painting-input');
            }
            if($file == null) {
                $file = $request->files->get('setup-plan-work-on-paper-input');
            }
            if($file == null) {
                $file = $request->files->get('setup-plan-sculpture-input');
            }
            if($file != null) {
                return $this->saveFile($file, 'setup_plan_images');
            } else {
                $file = $request->files->get('recommendation-image-default-input');
                if($file == null) {
                    $file = $request->files->get('recommendation-image-painting-input');
                }
                if($file == null) {
                    $file = $request->files->get('recommendation-image-work-on-paper-input');
                }
                if($file == null) {
                    $file = $request->files->get('recommendation-image-sculpture-input');
                }
                if($file != null) {
                    return $this->saveFile($file, 'recommendation_images');
                } else {
                    $response = new Response(json_encode(array('hash' => null, 'error' => 'Upload failed')));
                    $response->headers->set('Content-Type', 'application/json');
                    return $response;
                }
            }
        }
    }

    private function saveFile($file, $folder)
    {
        $extension = $file->getClientOriginalExtension();
        $type = null;
        switch($extension) {
            case 'jpg':
            case 'JPG':
            case 'png':
            case 'PNG':
            case 'tif':
            case 'TIF':
            case 'HEIC':
                $type = 'image';
                break;
            case 'pdf':
            case 'PDF':
                $type = 'pdf';
                break;
        }
        if ($type == null) {
            $response = new Response(json_encode(array('image' => null, 'error' => 'File type not supported. Allowed types: JPG, PNG, TIF, PDF')));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        } else {
            $filenameNoExt = round(microtime(true) * 1000);
            $file->move($folder, $filenameNoExt . '.' . $extension);

            $response = new Response(json_encode(array('image' => '/' . $folder . '/' . $filenameNoExt . '.' . $extension)));
            $response->headers->set('Content-Type', 'application/json');
            return $response;
        }
    }
}
