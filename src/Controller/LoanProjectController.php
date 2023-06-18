<?php

namespace App\Controller;

use App\Entity\LoanProject;
use App\Entity\Organisation;
use App\Entity\Representative;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class LoanProjectController extends AbstractController
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @Route("/{_locale}/loan_project/{id}/{action}", name="loan_project", defaults={ "id"="", "action"="" })
     */
    public function loanProject(Request $request, $id, $action)
    {
        $locale = $request->get('_locale');
        $locales = $this->getParameter('locales');
        //Set default locale if locale is missing
        if($locale === null || !in_array($locale, $locales)) {
            return $this->redirectToRoute('loan_project', array('_locale' => $locales[0], 'id' => $id, 'action' => $action));
        }
        if(!$this->getUser()) {
            return $this->redirectToRoute('main');
        } else if(!$this->getUser()->getRoles()) {
            return $this->redirectToRoute('main');
        } else if (!in_array('ROLE_USER', $this->getUser()->getRoles(), true)) {
            return $this->redirectToRoute('main');
        }

        $em = $this->container->get('doctrine')->getManager();

        $loanProject = new LoanProject();
        $organisationName = null;
        $representativeName = null;
        if (!empty($id)) {
            $loanProject = null;
            /* @var $loanProjects LoanProject[] */
            $loanProjects = $em->createQueryBuilder()
                ->select('l')
                ->from(LoanProject::class, 'l')
                ->where('l.id = :id')
                ->setParameter('id', $id)
                ->orderBy('l.alias')
                ->getQuery()
                ->getResult();
            foreach ($loanProjects as $loan) {
                $loanProject = $loan;
                $organisationName = $loan->getOrganisationName();
                $representativeName = $loan->getRepresentativeName();
            }
        }
        if($action == 'delete' && !empty($id) && $loanProject != null) {
            $em->remove($loanProject);
            $em->flush();
            return $this->redirectToRoute('loan_projects');
        } else {
            $organisationNames = [];
            $organisations = [];
            $orgs = $em->createQueryBuilder()
                ->select('o')
                ->from(Organisation::class, 'o')
                ->orderBy('o.alias')
                ->getQuery()
                ->getResult();
            foreach ($orgs as $org) {
                $organisationNames[$org->alias] = $org->id;
                $organisations[$org->id] = $org->alias;
            }

            $representativeNames = [];
            $representatives = [];
            $reps = $em->createQueryBuilder()
                ->select('r')
                ->from(Representative::class, 'r')
                ->orderBy('r.alias')
                ->getQuery()
                ->getResult();
            foreach ($reps as $rep) {
                $representativeNames[$rep->alias] = $rep->id;
                $representatives[$rep->id] = $rep->alias;
            }

            $t = $this->translator;

            $organisationNames[$t->trans('Other')] = 'customOption';
            $representativeNames[$t->trans('Other')] = 'customOption';

            $form = $this->createFormBuilder($loanProject)
                ->add('alias', TextType::class, ['required' => false, 'label' => $t->trans('Alias'), 'attr' => ['placeholder' => $t->trans('Alias of your choice (optional)')]])
                ->add('title', TextType::class, ['label' => $t->trans('Title'), 'attr' => ['placeholder' => $t->trans('Title of the loan project')]])
                ->add('organisation', ChoiceType::class, ['required' => false, 'choices' => $organisationNames, 'label' => $t->trans('Name of the organising institution')])
                ->add('organisation_name', TextType::class, ['required' => false, 'label' => ' ', 'label_attr' => ['class' => 'hidden-form-label-with-space']])
                ->add('address', TextType::class, ['required' => false, 'label' => $t->trans('Address'), 'attr' => ['placeholder' => $t->trans('Street + house number')]])
                ->add('postal', TextType::class, ['required' => false, 'label' => $t->trans('Postal code'), 'attr' => ['placeholder' => $t->trans('Ex. 9000')]])
                ->add('city', TextType::class, ['required' => false, 'label' => $t->trans('City'), 'attr' => ['placeholder' => $t->trans('Ex. Ghent')]])
                ->add('state_province', TextType::class, ['required' => false, 'label' => $t->trans('Province or state'), 'attr' => ['placeholder' => $t->trans('Ex. East Flanders')]])
                ->add('country', TextType::class, ['required' => false, 'label' => $t->trans('Country'), 'attr' => ['placeholder' => $t->trans('Ex. Belgium')]])
                ->add('url', TextType::class, ['required' => false, 'label' => $t->trans('Link to webpage if applicable')])
                ->add('start_date', DateType::class, ['required' => false, 'label' => $t->trans('Start date loan'), 'widget' => 'single_text'])
                ->add('end_date', DateType::class, ['required' => false, 'label' => $t->trans('End date loan'), 'widget' => 'single_text'])
                ->add('start_date_insured', DateType::class, ['required' => false, 'label' => $t->trans('Start date insurance coverage '), 'widget' => 'single_text'])
                ->add('end_date_insured', DateType::class, ['required' => false, 'label' => $t->trans('End date insurance coverage '), 'widget' => 'single_text'])
                ->add('loan_number', TextType::class, ['required' => false, 'label' => $t->trans('Loan number or dossier number')])
                ->add('representative', ChoiceType::class, ['required' => false, 'choices' => $representativeNames, 'label' => $t->trans('Name')])
                ->add('representative_name', TextType::class, ['required' => false, 'label' => ' ', 'label_attr' => ['class' => 'hidden-form-label-with-space']])
                ->add('representative_role', TextType::class, ['required' => false, 'label' => $t->trans('Role')])
                ->add('representative_email', TextType::class, ['required' => false, 'label' => $t->trans('E-mail')])
                ->add('representative_phone', TextType::class, ['required' => false, 'label' => $t->trans('Telephone')])
                ->add('notes', TextareaType::class, ['required' => false, 'label' => $t->trans('Notes'), 'attr' => ['placeholder' => $t->trans('Own notes about this loan project'), 'oninput' => 'fixTextareaheight()']])
                ->add('submit', SubmitType::class, ['label' => $t->trans('Save')])
                ->getForm();
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $formData = $form->getData();
                if (empty($formData->getAlias())) {
                    $formData->setAlias($formData->getTitle());
                }
                $em->persist($formData);
                $em->flush();
                return $this->redirectToRoute('loan_projects');
            } else {
                $translatedRoutes = array();
                foreach($locales as $l) {
                    $translatedRoutes[] = array(
                        'lang' => $l,
                        'url' => $this->generateUrl('loan_project', array('_locale' => $l, 'id' => $id, 'action' => $action)),
                        'active' => $l === $locale
                    );
                }

                return $this->render('loan_project.html.twig', [
                    'current_page' => 'loan_projects',
                    'organisations' => $organisationNames,
                    'representatives' => $representativeNames,
                    'org_name' => $organisationName,
                    'rep_name' => $representativeName,
                    'new' => empty($id),
                    'form' => $form->createView(),
                    'translated_routes' => $translatedRoutes
                ]);
            }
        }
    }
}
