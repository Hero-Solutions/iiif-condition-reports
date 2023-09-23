<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Twig\Environment;

class ManualController extends AbstractController
{
    /**
     * @Route("/{_locale}/manual", name="manual")
     */
    public function manual(Request $request, Environment $env)
    {
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('manual', array('_locale' => $locales[0]));
        }

        $translatedRoutes = array();
        foreach($locales as $l) {
            $translatedRoutes[] = array(
                'lang' => $l,
                'url' => $this->generateUrl('manual', array('_locale' => $l)),
                'active' => $l === $locale
            );
        }

        if($env->getLoader()->exists('manual_' . $locale . '.html.twig')) {
            return $this->render('manual_' . $locale . '.html.twig', [
                'current_page' => 'manual',
                'translated_routes' => $translatedRoutes
            ]);
        } else {
            return $this->render('manual_en.html.twig', [
                'current_page' => 'manual',
                'translated_routes' => $translatedRoutes
            ]);
        }
    }
}
