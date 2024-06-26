<?php

namespace App\Controller;

use App\Entity\DatahubData;
use App\Entity\InventoryNumber;
use App\Entity\LoanProject;
use App\Entity\Organisation;
use App\Entity\Report;
use App\Entity\Representative;
use App\Utils\IIIFUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class LoanProjectsController extends AbstractController
{
    /**
     * @Route("/{_locale}/loan_projects", name="loan_projects")
     */
    public function loanProjects(Request $request)
    {
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('loan_projects', array('_locale' => $locales[0]));
        }
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $em = $this->container->get('doctrine')->getManager();

        $organisationNames = [];
        $orgs = $em->createQueryBuilder()
            ->select('o')
            ->from(Organisation::class, 'o')
            ->getQuery()
            ->getResult();
        foreach ($orgs as $org) {
            $organisationNames[$org->id] = $org->alias;
        }

        $representativeNames = [];
        $reps = $em->createQueryBuilder()
            ->select('r')
            ->from(Representative::class, 'r')
            ->getQuery()
            ->getResult();
        foreach ($reps as $rep) {
            $representativeNames[$rep->id] = $rep->alias;
        }

        $searchResults = array();
        $loanProjects = $em->createQueryBuilder()
            ->select('l')
            ->from(LoanProject::class, 'l')
            ->orderBy('l.alias')
            ->getQuery()
            ->getResult();
        foreach ($loanProjects as $loanProject) {
            $searchResults[] = $loanProject;
        }

        $translatedRoutes = array();
        foreach($locales as $l) {
            $translatedRoutes[] = array(
                'lang' => $l,
                'url' => $this->generateUrl('loan_projects', array('_locale' => $l)),
                'active' => $l === $locale
            );
        }

        return $this->render('loan_projects.html.twig', [
            'current_page' => 'loan_projects',
            'loan_projects' => $searchResults,
            'organisation_names' => $organisationNames,
            'representative_names' => $representativeNames,
            'translated_routes' => $translatedRoutes
        ]);

    }
}
