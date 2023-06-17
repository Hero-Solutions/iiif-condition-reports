<?php

namespace App\Controller;

use App\Entity\LoanProject;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        if (!empty($id)) {
            $loanProject = null;
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
            }
        }
        if($action == 'delete' && !empty($id) && $loanProject != null) {
            $em->remove($loanProject);
            $em->flush();
            return $this->redirectToRoute('loan_projects');
        } else {
            $t = $this->translator;
            $form = $this->createFormBuilder($loanProject)
                ->add('alias', TextType::class, ['required' => false, 'label' => $t->trans('Alias'), 'attr' => ['placeholder' => $t->trans('Alias of your choice (optional)')]])
                ->add('title', TextType::class, ['label' => $t->trans('Title'), 'attr' => ['placeholder' => $t->trans('Title of the loan project')]])
                ->add('address', TextType::class, ['required' => false, 'label' => $t->trans('Address'), 'attr' => ['placeholder' => $t->trans('Street + house number')]])
                ->add('postal', TextType::class, ['required' => false, 'label' => $t->trans('Postal code'), 'attr' => ['placeholder' => $t->trans('Ex. 9000')]])
                ->add('city', TextType::class, ['required' => false, 'label' => $t->trans('City'), 'attr' => ['placeholder' => $t->trans('Ex. Ghent')]])
                ->add('state_province', TextType::class, ['required' => false, 'label' => $t->trans('Province or state'), 'attr' => ['placeholder' => $t->trans('Ex. East Flanders')]])
                ->add('country', TextType::class, ['required' => false, 'label' => $t->trans('Country'), 'attr' => ['placeholder' => $t->trans('Ex. Belgium')]])
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
                    'new' => empty($id),
                    'form' => $form->createView(),
                    'translated_routes' => $translatedRoutes
                ]);
            }
        }
    }
}
