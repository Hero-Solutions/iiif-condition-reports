<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Actor;
use App\Entity\OrganizationContact;
use App\Form\ActorType;
use App\Form\OrganizationContactType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminActorController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{_locale<nl|en>}/admin/actors', name: 'admin_actors_index', methods: ['GET'])]
    public function index(): Response
    {
        $actors = $this->entityManager
            ->getRepository(Actor::class)
            ->createQueryBuilder('actor')
            ->orderBy('actor.alias', 'ASC')
            ->addOrderBy('actor.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('admin/actors/index.html.twig', [
            'actors' => $actors,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/new', name: 'admin_actors_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $contactOrganization = $this->contactOrganization($request);
        $actor = new Actor('', $contactOrganization instanceof Actor ? Actor::TYPE_PERSON : Actor::TYPE_ORGANIZATION);
        $form = $this->createForm(ActorType::class, $actor, [
            'lock_type' => $contactOrganization instanceof Actor,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveActor($actor)) {
            $this->addFlash('success', 'actors.saved');

            if ($contactOrganization instanceof Actor && $actor->getType() === Actor::TYPE_PERSON) {
                $this->entityManager->persist(new OrganizationContact($contactOrganization, $actor));
                $this->entityManager->flush();

                return $this->redirectToActor($contactOrganization, $request);
            }

            return $this->redirectToRoute('admin_actors_edit', [
                '_locale' => $request->getLocale(),
                'id' => $actor->getId(),
            ]);
        }

        return $this->render('admin/actors/form.html.twig', [
            'actor' => $actor,
            'form' => $form,
            'contacts' => [],
            'is_new' => true,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/{id}/edit', name: 'admin_actors_edit', methods: ['GET', 'POST'])]
    public function edit(Actor $actor, Request $request): Response
    {
        $form = $this->createForm(ActorType::class, $actor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->saveActor($actor)) {
            $this->addFlash('success', 'actors.saved');

            return $this->redirectToRoute('admin_actors_edit', [
                '_locale' => $request->getLocale(),
                'id' => $actor->getId(),
            ]);
        }

        return $this->render('admin/actors/form.html.twig', [
            'actor' => $actor,
            'form' => $form,
            'contacts' => $this->contactsForActor($actor),
            'is_new' => false,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/{id}/delete', name: 'admin_actors_delete', methods: ['POST'])]
    public function delete(Actor $actor, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_actor_delete_' . $actor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($actor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.deleted');

        return $this->redirectToRoute('admin_actors_index', [
            '_locale' => $request->getLocale(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/{actorId}/contacts/new', name: 'admin_actor_contacts_new', methods: ['GET', 'POST'])]
    public function newContact(int $actorId, Request $request): Response
    {
        $actor = $this->findActor($actorId);
        $this->assertOrganization($actor);
        $contact = new OrganizationContact($actor);
        $form = $this->createForm(OrganizationContactType::class, $contact, [
            'people' => $this->people(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->organizationContactExists($contact)) {
                $this->addFlash('error', 'actors.contact_exists');

                return $this->redirectToActor($actor, $request);
            }

            $this->entityManager->persist($contact);
            $this->entityManager->flush();
            $this->addFlash('success', 'actors.contact_saved');

            return $this->redirectToActor($actor, $request);
        }

        return $this->render('admin/actors/contact_form.html.twig', [
            'actor' => $actor,
            'form' => $form,
            'is_new' => true,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/{actorId}/contacts/{id}/edit', name: 'admin_actor_contacts_edit', methods: ['GET', 'POST'])]
    public function editContact(int $actorId, OrganizationContact $contact, Request $request): Response
    {
        $actor = $this->findActor($actorId);
        $this->assertOrganization($actor);

        if ($contact->getOrganization()->getId() !== $actor->getId()) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(OrganizationContactType::class, $contact, [
            'people' => $this->people(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->organizationContactExists($contact)) {
                $this->addFlash('error', 'actors.contact_exists');

                return $this->redirectToActor($actor, $request);
            }

            $this->entityManager->flush();
            $this->addFlash('success', 'actors.contact_saved');

            return $this->redirectToActor($actor, $request);
        }

        return $this->render('admin/actors/contact_form.html.twig', [
            'actor' => $actor,
            'form' => $form,
            'is_new' => false,
        ]);
    }

    #[Route('/{_locale<nl|en>}/admin/actors/{actorId}/contacts/{id}/delete', name: 'admin_actor_contacts_delete', methods: ['POST'])]
    public function deleteContact(int $actorId, OrganizationContact $contact, Request $request): Response
    {
        $actor = $this->findActor($actorId);
        $this->assertOrganization($actor);

        if ($contact->getOrganization()->getId() !== $actor->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('admin_contact_delete_' . $contact->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($contact);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.contact_deleted');

        return $this->redirectToActor($actor, $request);
    }

    private function saveActor(Actor $actor): bool
    {
        $originalType = $this->entityManager->getUnitOfWork()->getOriginalEntityData($actor)['type'] ?? $actor->getType();

        if ($actor->getId() !== null && $originalType !== $actor->getType() && $this->actorTypeIsInUse($actor, $originalType)) {
            $actor->setType($originalType);
            $this->addFlash('error', 'actors.type_in_use');

            return false;
        }

        $existingActor = $this->entityManager
            ->getRepository(Actor::class)
            ->findOneBy(['name' => $actor->getName()]);

        if ($existingActor instanceof Actor && $existingActor->getId() !== $actor->getId()) {
            $this->addFlash('error', 'actors.name_exists');

            return false;
        }

        if ($actor->getAlias() === null) {
            $actor->setAlias($actor->getName());
        }

        $this->entityManager->persist($actor);
        $this->entityManager->flush();

        return true;
    }

    private function actorTypeIsInUse(Actor $actor, string $originalType): bool
    {
        $field = $originalType === Actor::TYPE_PERSON ? 'person' : 'organization';

        return $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->findOneBy([$field => $actor]) instanceof OrganizationContact;
    }

    private function findActor(int $id): Actor
    {
        $actor = $this->entityManager->getRepository(Actor::class)->find($id);

        if (!$actor instanceof Actor) {
            throw $this->createNotFoundException();
        }

        return $actor;
    }

    private function assertOrganization(Actor $actor): void
    {
        if ($actor->getType() !== Actor::TYPE_ORGANIZATION) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * @return list<OrganizationContact>
     */
    private function contactsForActor(Actor $actor): array
    {
        return $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->createQueryBuilder('contact')
            ->addSelect('person')
            ->join('contact.person', 'person')
            ->andWhere('contact.organization = :actor')
            ->setParameter('actor', $actor)
            ->orderBy('person.alias', 'ASC')
            ->addOrderBy('person.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Actor>
     */
    private function people(): array
    {
        return $this->entityManager
            ->getRepository(Actor::class)
            ->createQueryBuilder('person')
            ->andWhere('person.type = :type')
            ->setParameter('type', Actor::TYPE_PERSON)
            ->orderBy('person.alias', 'ASC')
            ->addOrderBy('person.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function organizationContactExists(OrganizationContact $contact): bool
    {
        $person = $contact->getPerson();

        if (!$person instanceof Actor) {
            return false;
        }

        $existing = $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->findOneBy([
                'organization' => $contact->getOrganization(),
                'person' => $person,
            ]);

        return $existing instanceof OrganizationContact && $existing->getId() !== $contact->getId();
    }

    private function contactOrganization(Request $request): ?Actor
    {
        $id = filter_var($request->query->get('contact_for'), FILTER_VALIDATE_INT);

        if (!is_int($id) || $id < 1) {
            return null;
        }

        $actor = $this->entityManager->getRepository(Actor::class)->find($id);

        return $actor instanceof Actor && $actor->getType() === Actor::TYPE_ORGANIZATION ? $actor : null;
    }

    private function redirectToActor(Actor $actor, Request $request): Response
    {
        return $this->redirectToRoute('admin_actors_edit', [
            '_locale' => $request->getLocale(),
            'id' => $actor->getId(),
        ]);
    }
}
