<?php

namespace App\Controller;

use App\Entity\Image;
use App\Utils\IIIFUtil;
use App\Utils\ReportTemplateData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class JsonDataController extends AbstractController
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route("/data/{id}.json", name: "json_data")]
    public function jsonData(Request $request, $id)
    {
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $requestUri = $request->getRequestUri();
        $uri = $request->getUri();
        $pos = strpos($uri, $requestUri);
        if($pos !== false) {
            $uri = substr($uri, 0, $pos);
        } else {
            $uri = '../..';
        }
        $jsonData = ReportTemplateData::getJsonData($this->entityManager, $id, $uri);

        $headers = array('Content-Type' => 'application/json');
        return new Response(json_encode($jsonData, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE), 200, $headers);
    }
}
