<?php

namespace App\Controller;

use App\Utils\ReportTemplateData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ViewReportController extends AbstractController
{
    /**
     * @Route("/{_locale}/view/{id}", name="view")
     */
    public function view(Request $request, $id)
    {
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('view', array('_locale' => $locales[0], 'id' => $id));
        }
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $em = $this->get('doctrine')->getManager();
        $reportReasons = $this->getParameter('report_reasons');
        $objectTypes = $this->getParameter('object_types');
        $actorTypes = $this->getParameter('actor_types');
        $reportFields = $this->getParameter('report_fields');
        $pictures = $this->getParameter('pictures');

        $translatedRoutes = array();
        foreach($locales as $l) {
            $translatedRoutes[] = array(
                'lang' => $l,
                'url' => $this->generateUrl('view', array('_locale' => $l, 'id' => $id)),
                'active' => $l === $locale
            );
        }

        $viewData = ReportTemplateData::getViewData($em, $reportReasons, $objectTypes, $actorTypes, $reportFields, $pictures, $id, $translatedRoutes);
        if(array_key_exists('is_draft', $viewData['prefilled_data']) && array_key_exists('base_id', $viewData['prefilled_data'])) {
            if($viewData['prefilled_data']['is_draft']) {
                $viewData['edit_url'] = $this->generateUrl('create_existing', array('_locale' => $locale, 'baseId' => $viewData['prefilled_data']['base_id']));
            }
        }

        return $this->render('report.html.twig', $viewData);
    }
}
