<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ObjectRecord;
use App\Entity\Actor;
use App\Entity\OrganizationContact;
use App\Entity\Project;
use App\Entity\ProjectActor;
use App\Entity\ProjectObjectActor;
use App\Entity\ProjectObject;
use App\Entity\Report;
use App\Entity\ReportSeries;
use App\Entity\User;
use App\Form\ProjectType;
use App\Service\ObjectThumbnailProvider;
use App\Value\ActorRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProjectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ObjectThumbnailProvider $thumbnailProvider,
    ) {
    }

    #[Route('/{_locale<nl|en>}/projects', name: 'projects_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $queryBuilder = $this->entityManager
            ->getRepository(Project::class)
            ->createQueryBuilder('project')
            ->orderBy('project.updatedAt', 'DESC')
            ->setMaxResults(100);

        if ($q !== '') {
            $queryBuilder
                ->andWhere('project.title LIKE :q OR project.referenceCode LIKE :q OR project.description LIKE :q')
                ->setParameter('q', '%' . $q . '%');
        }

        $projects = $queryBuilder->getQuery()->getResult();

        return $this->render('projects/index.html.twig', [
            'projects' => $projects,
            'q' => $q,
            'object_counts' => $this->projectObjectCounts($projects),
            'report_counts' => $this->projectReportCounts($projects),
        ]);
    }

    /**
     * @param list<Project> $projects
     * @return array<int, int>
     */
    private function projectObjectCounts(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->createQueryBuilder('projectObject')
            ->select('IDENTITY(projectObject.project) AS project_id, COUNT(projectObject.id) AS object_count')
            ->andWhere('projectObject.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->groupBy('projectObject.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['project_id']] = (int) $row['object_count'];
        }

        return $counts;
    }

    /**
     * @param list<Project> $projects
     * @return array<int, array{total: int, finalized: int}>
     */
    private function projectReportCounts(array $projects): array
    {
        if ($projects === []) {
            return [];
        }

        $rows = $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->select('IDENTITY(report.project) AS project_id, COUNT(report.id) AS total_count, SUM(CASE WHEN report.status = :finalized THEN 1 ELSE 0 END) AS finalized_count')
            ->andWhere('report.project IN (:projects)')
            ->setParameter('projects', $projects)
            ->setParameter('finalized', Report::STATUS_FINALIZED)
            ->groupBy('report.project')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['project_id']] = [
                'total' => (int) $row['total_count'],
                'finalized' => (int) $row['finalized_count'],
            ];
        }

        return $counts;
    }

    #[Route('/{_locale<nl|en>}/projects/new', name: 'projects_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $project = new Project();
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->normalizeCustomType();
            $this->entityManager->persist($project);
            $this->entityManager->flush();
            $this->addFlash('success', 'projects.saved');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $project->getId(),
            ]);
        }

        return $this->render('projects/form.html.twig', [
            'project' => $project,
            'form' => $form,
            'add_object_form' => null,
            'project_objects' => [],
            'project_actors' => [],
            'project_object_actors' => [],
            'actor_type_choices' => Actor::typeChoices(),
            'actor_role_choices' => ActorRole::choices(),
            'actor_options' => [],
            'contact_options' => [],
            'is_new' => true,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{id}', name: 'projects_edit', methods: ['GET', 'POST'])]
    public function edit(Project $project, Request $request): Response
    {
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $project->normalizeCustomType();
            $this->entityManager->flush();
            $this->addFlash('success', 'projects.saved');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $project->getId(),
            ]);
        }

        $projectObjects = $this->projectObjects($project);

        if (!$form->isSubmitted()) {
            $this->ensureReportSeriesForProjectObjects($projectObjects);
        }

        $objectResults = $this->objectSearchResults($project, $request);
        $thumbnailObjects = array_merge(
            $this->objectsFromProjectObjects($projectObjects),
            $objectResults,
        );

        $reportsBySeries = $this->reportsBySeries($project);

        return $this->render('projects/form.html.twig', [
            'project' => $project,
            'form' => $form,
            'project_objects' => $projectObjects,
            'project_actors' => $this->projectActors($project),
            'project_object_actors' => $this->projectObjectActors($project),
            'object_results' => $objectResults,
            'report_series_by_object' => $this->reportSeriesByObject($project),
            'reports_by_series' => $reportsBySeries,
            'report_author_names' => $this->reportAuthorNames($reportsBySeries),
            'object_type_choices' => ObjectRecord::objectTypeChoices(),
            'actor_type_choices' => Actor::typeChoices(),
            'actor_role_choices' => ActorRole::choices(),
            'actor_options' => $this->actorOptions(),
            'contact_options' => $this->contactOptions(),
            'object_inventory_number' => trim((string) $request->query->get('object_inventory_number', '')),
            'object_match' => $request->query->get('object_match') === 'starts_with' ? 'starts_with' : 'exact',
            'thumbnail_urls' => $this->thumbnailProvider->thumbnailsForObjects($thumbnailObjects),
            'is_new' => false,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{id}/objects/{objectId}/add', name: 'projects_objects_add', methods: ['POST'])]
    public function addObject(Project $project, int $objectId, Request $request): Response
    {
        $object = $this->entityManager
            ->getRepository(ObjectRecord::class)
            ->find($objectId);

        if (!$object instanceof ObjectRecord) {
            $this->addFlash('error', 'projects.object_not_found');

            return $this->redirectToProject($project, $request);
        }

        $existingLink = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->findOneBy([
                'project' => $project,
                'objectRecord' => $object,
            ]);

        if ($existingLink instanceof ProjectObject) {
            $this->addFlash('error', 'projects.object_already_added');

            return $this->redirectToProject($project, $request);
        }

        if ($object->getObjectType() === null) {
            $objectType = ObjectRecord::normalizeObjectType((string) $request->request->get('object_type', ''));
            $customObjectType = mb_substr(trim((string) $request->request->get('custom_object_type', '')), 0, 100);

            if ($objectType === null || ($objectType === ObjectRecord::TYPE_OTHER && $customObjectType === '')) {
                $this->addFlash('error', 'projects.object_type_required');

                return $this->redirectToProject($project, $request);
            }

            $object
                ->setObjectType($objectType)
                ->setCustomObjectType($customObjectType);
        }

        $link = new ProjectObject($project, $object);
        $link->setSortOrder(count($this->projectObjects($project)) + 1);

        $this->entityManager->persist($link);
        $this->ensureReportSeriesForProjectObject($link);
        $this->entityManager->flush();
        $this->addFlash('success', 'projects.object_added');

        return $this->redirectToProject($project, $request);
    }

    #[Route('/{_locale<nl|en>}/projects/{id}/actors/add', name: 'projects_actors_add', methods: ['POST'])]
    public function addProjectActor(Project $project, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('project_actor_add_' . $project->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actorData = $this->actorAssignmentData($request);

        if ($actorData === null) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToProject($project, $request);
        }

        $actor = $this->findOrCreateActor($actorData);

        if (!$actor instanceof Actor) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToProject($project, $request);
        }

        $projectActor = new ProjectActor($project, $actor);
        $projectActor
            ->setRole($actorData['role'])
            ->setCustomRole($actorData['custom_role'])
            ->normalizeCustomRole();

        $this->entityManager->persist($projectActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.added');

        return $this->redirectToProject($project, $request);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/actors/{id}/remove', name: 'projects_actors_remove', methods: ['POST'])]
    public function removeProjectActor(int $projectId, ProjectActor $projectActor, Request $request): Response
    {
        if ($projectActor->getProject()->getId() !== $projectId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_actor_remove_' . $projectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($projectActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.removed');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/actors/{id}/edit', name: 'projects_actors_edit_assignment', methods: ['POST'])]
    public function editProjectActorAssignment(int $projectId, ProjectActor $projectActor, Request $request): Response
    {
        if ($projectActor->getProject()->getId() !== $projectId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_actor_edit_' . $projectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->updateActorAssignment($projectActor, $request)) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'actors.saved');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/actors/{id}/contact', name: 'projects_actors_contact', methods: ['POST'])]
    public function updateProjectActorContact(int $projectId, ProjectActor $projectActor, Request $request): Response
    {
        if ($projectActor->getProject()->getId() !== $projectId) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_actor_contact_' . $projectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $contactResult = $this->contactPersonAssignment($projectActor->getActor(), $request);

        if (!$contactResult['valid']) {
            $this->addFlash('error', 'actors.invalid_contact');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $projectActor->setContactPerson($contactResult['contact_person']);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.contact_saved');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{projectObjectId}/actors/add', name: 'projects_objects_actors_add', methods: ['POST'])]
    public function addProjectObjectActor(int $projectId, int $projectObjectId, Request $request): Response
    {
        $projectObject = $this->projectObjectForProject($projectId, $projectObjectId);

        if (!$this->isCsrfTokenValid('project_object_actor_add_' . $projectObject->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actorData = $this->actorAssignmentData($request);

        if ($actorData === null) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $actor = $this->findOrCreateActor($actorData);

        if (!$actor instanceof Actor) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $projectObjectActor = new ProjectObjectActor($projectObject, $actor);
        $projectObjectActor
            ->setRole($actorData['role'])
            ->setCustomRole($actorData['custom_role'])
            ->normalizeCustomRole();

        $this->entityManager->persist($projectObjectActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.added');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{projectObjectId}/actors/{id}/contact', name: 'projects_objects_actors_contact', methods: ['POST'])]
    public function updateProjectObjectActorContact(int $projectId, int $projectObjectId, ProjectObjectActor $projectObjectActor, Request $request): Response
    {
        $projectObject = $this->projectObjectForProject($projectId, $projectObjectId);

        if ($projectObjectActor->getProjectObject()->getId() !== $projectObject->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_object_actor_contact_' . $projectObjectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $contactResult = $this->contactPersonAssignment($projectObjectActor->getActor(), $request);

        if (!$contactResult['valid']) {
            $this->addFlash('error', 'actors.invalid_contact');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $projectObjectActor->setContactPerson($contactResult['contact_person']);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.contact_saved');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{projectObjectId}/actors/{id}/remove', name: 'projects_objects_actors_remove', methods: ['POST'])]
    public function removeProjectObjectActor(int $projectId, int $projectObjectId, ProjectObjectActor $projectObjectActor, Request $request): Response
    {
        $projectObject = $this->projectObjectForProject($projectId, $projectObjectId);

        if ($projectObjectActor->getProjectObject()->getId() !== $projectObject->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_object_actor_remove_' . $projectObjectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($projectObjectActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.removed');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{projectObjectId}/actors/{id}/edit', name: 'projects_objects_actors_edit_assignment', methods: ['POST'])]
    public function editProjectObjectActorAssignment(int $projectId, int $projectObjectId, ProjectObjectActor $projectObjectActor, Request $request): Response
    {
        $projectObject = $this->projectObjectForProject($projectId, $projectObjectId);

        if ($projectObjectActor->getProjectObject()->getId() !== $projectObject->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('project_object_actor_edit_' . $projectObjectActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->updateActorAssignment($projectObjectActor, $request)) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToRoute('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $projectId,
            ]);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'actors.saved');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    #[Route('/{_locale<nl|en>}/projects/{projectId}/objects/{id}/remove', name: 'projects_objects_remove', methods: ['POST'])]
    public function removeObject(int $projectId, ProjectObject $projectObject, Request $request): Response
    {
        if ($projectObject->getProject()->getId() !== $projectId) {
            throw $this->createNotFoundException();
        }

        $this->entityManager->remove($projectObject);
        $this->entityManager->flush();
        $this->addFlash('success', 'projects.object_removed');

        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $projectId,
        ]);
    }

    /**
     * @return list<ProjectObject>
     */
    private function projectObjects(Project $project): array
    {
        return $this->entityManager
            ->getRepository(ProjectObject::class)
            ->createQueryBuilder('projectObject')
            ->addSelect('objectRecord')
            ->join('projectObject.objectRecord', 'objectRecord')
            ->andWhere('projectObject.project = :project')
            ->setParameter('project', $project)
            ->orderBy('projectObject.sortOrder', 'ASC')
            ->addOrderBy('objectRecord.inventoryNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ObjectRecord>
     */
    private function objectSearchResults(Project $project, Request $request): array
    {
        $inventoryNumber = trim((string) $request->query->get('object_inventory_number', ''));
        $match = $request->query->get('object_match') === 'starts_with' ? 'starts_with' : 'exact';

        if ($inventoryNumber === '') {
            return [];
        }

        $queryBuilder = $this->entityManager
            ->getRepository(ObjectRecord::class)
            ->createQueryBuilder('object')
            ->orderBy('object.inventoryNumber', 'ASC')
            ->setMaxResults(20);

        $queryBuilder
            ->andWhere($match === 'exact' ? 'object.inventoryNumber = :inventoryNumber' : 'object.inventoryNumber LIKE :inventoryNumber')
            ->setParameter('inventoryNumber', $match === 'exact' ? $inventoryNumber : $inventoryNumber . '%');

        $linkedIds = array_values(array_filter(array_map(
            static fn (ProjectObject $projectObject): ?int => $projectObject->getObjectRecord()->getId(),
            $this->projectObjects($project),
        )));

        if ($linkedIds !== []) {
            $queryBuilder
                ->andWhere('object.id NOT IN (:linkedIds)')
                ->setParameter('linkedIds', $linkedIds);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    private function redirectToProject(Project $project, Request $request): Response
    {
        return $this->redirectToRoute('projects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $project->getId(),
        ]);
    }

    /**
     * @return list<ProjectActor>
     */
    private function projectActors(Project $project): array
    {
        return $this->entityManager
            ->getRepository(ProjectActor::class)
            ->createQueryBuilder('projectActor')
            ->addSelect('actor', 'contactPerson')
            ->join('projectActor.actor', 'actor')
            ->leftJoin('projectActor.contactPerson', 'contactPerson')
            ->andWhere('projectActor.project = :project')
            ->setParameter('project', $project)
            ->orderBy('projectActor.createdAt', 'ASC')
            ->addOrderBy('projectActor.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, list<ProjectObjectActor>>
     */
    private function projectObjectActors(Project $project): array
    {
        $actors = $this->entityManager
            ->getRepository(ProjectObjectActor::class)
            ->createQueryBuilder('projectObjectActor')
            ->addSelect('projectObject', 'actor', 'contactPerson')
            ->join('projectObjectActor.projectObject', 'projectObject')
            ->join('projectObjectActor.actor', 'actor')
            ->leftJoin('projectObjectActor.contactPerson', 'contactPerson')
            ->andWhere('projectObject.project = :project')
            ->setParameter('project', $project)
            ->orderBy('projectObject.sortOrder', 'ASC')
            ->addOrderBy('projectObjectActor.createdAt', 'ASC')
            ->addOrderBy('projectObjectActor.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($actors as $actor) {
            $projectObjectId = $actor->getProjectObject()->getId();

            if ($projectObjectId !== null) {
                $grouped[$projectObjectId][] = $actor;
            }
        }

        return $grouped;
    }

    /**
     * @return array{actor_id: ?int, actor_name: string, actor_type: string, role: string, custom_role: ?string}|null
     */
    private function actorAssignmentData(Request $request): ?array
    {
        $actorId = $this->positiveInt($request->request->get('actor_id'));
        $actorName = mb_substr(trim((string) $request->request->get('actor_name', '')), 0, 255);
        $actorType = (string) $request->request->get('actor_type', Actor::TYPE_ORGANIZATION);
        $role = ActorRole::normalize((string) $request->request->get('role', ''));
        $customRole = mb_substr(trim((string) $request->request->get('custom_role', '')), 0, 100);

        if (($actorName === '' && $actorId === null) || $role === null) {
            return null;
        }

        if (!in_array($actorType, [Actor::TYPE_ORGANIZATION, Actor::TYPE_PERSON], true)) {
            return null;
        }

        if ($role === ActorRole::OTHER && $customRole === '') {
            return null;
        }

        return [
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'actor_type' => $actorType,
            'role' => $role,
            'custom_role' => $role === ActorRole::OTHER ? $customRole : null,
        ];
    }

    /**
     * @param array{actor_id: ?int, actor_name: string, actor_type: string} $actorData
     */
    private function findOrCreateActor(array $actorData): ?Actor
    {
        if ($actorData['actor_id'] !== null) {
            $actor = $this->entityManager
                ->getRepository(Actor::class)
                ->find($actorData['actor_id']);

            return $actor instanceof Actor ? $actor : null;
        }

        $actor = $this->entityManager
            ->getRepository(Actor::class)
            ->findOneBy(['name' => $actorData['actor_name']]);

        if ($actor instanceof Actor) {
            return $actor;
        }

        $actor = new Actor($actorData['actor_name'], $actorData['actor_type']);
        $this->entityManager->persist($actor);

        return $actor;
    }

    private function updateActorAssignment(ProjectActor|ProjectObjectActor $assignment, Request $request): bool
    {
        $actor = $assignment->getActor();
        $actorName = mb_substr(trim((string) $request->request->get('actor_name', '')), 0, 255);
        $actorType = (string) $request->request->get('actor_type', Actor::TYPE_ORGANIZATION);
        $role = ActorRole::normalize((string) $request->request->get('role', ''));
        $customRole = mb_substr(trim((string) $request->request->get('custom_role', '')), 0, 100);

        if ($actorName === '' || $role === null) {
            return false;
        }

        if (!in_array($actorType, [Actor::TYPE_ORGANIZATION, Actor::TYPE_PERSON], true)) {
            return false;
        }

        if ($role === ActorRole::OTHER && $customRole === '') {
            return false;
        }

        $existingActor = $this->entityManager
            ->getRepository(Actor::class)
            ->findOneBy(['name' => $actorName]);

        if ($existingActor instanceof Actor && $existingActor->getId() !== $actor->getId()) {
            return false;
        }

        $actor
            ->setName($actorName)
            ->setType($actorType)
            ->setAlias($this->requestText($request, 'actor_alias'))
            ->setLogo($this->requestText($request, 'actor_logo'))
            ->setVat($this->requestText($request, 'actor_vat'))
            ->setAddress($this->requestText($request, 'actor_address'))
            ->setPostal($this->requestText($request, 'actor_postal'))
            ->setCity($this->requestText($request, 'actor_city'))
            ->setStateProvince($this->requestText($request, 'actor_state_province'))
            ->setCountry($this->requestText($request, 'actor_country'))
            ->setEmail($this->requestText($request, 'actor_email'))
            ->setWebsite($this->requestText($request, 'actor_website'))
            ->setPhone($this->requestText($request, 'actor_phone'))
            ->setMobile($this->requestText($request, 'actor_mobile'))
            ->setNotes($this->requestText($request, 'actor_notes'));

        $assignment
            ->setRole($role)
            ->setCustomRole($role === ActorRole::OTHER ? $customRole : null)
            ->normalizeCustomRole();

        if ($actor->getType() !== Actor::TYPE_ORGANIZATION) {
            $assignment->setContactPerson(null);

            return true;
        }

        $contactChoice = (string) $request->request->get('contact_person_id', '__new__');

        if ($contactChoice === '') {
            $assignment->setContactPerson(null);

            return true;
        }

        if ($contactChoice !== '__new__') {
            $contactPersonId = $this->positiveInt($contactChoice);

            if ($contactPersonId === null) {
                return false;
            }

            $contactPerson = $this->entityManager
                ->getRepository(OrganizationContact::class)
                ->find($contactPersonId);

            if (!$contactPerson instanceof OrganizationContact || $contactPerson->getOrganization()->getId() !== $actor->getId()) {
                return false;
            }

            $assignment->setContactPerson($contactPerson);

            return true;
        }

        $contactName = mb_substr(trim((string) $request->request->get('contact_name', '')), 0, 255);

        if ($contactName === '') {
            $assignment->setContactPerson(null);

            return true;
        }

        $contactPerson = $assignment->getContactPerson();

        if (!$contactPerson instanceof OrganizationContact || $contactPerson->getOrganization()->getId() !== $actor->getId()) {
            $contactPerson = $this->organizationContactForName($actor, $contactName);
        }

        if (!$contactPerson instanceof OrganizationContact) {
            return false;
        }

        $contactPerson
            ->setName($contactName)
            ->setAlias($this->requestText($request, 'contact_alias'))
            ->setFunctionTitle($this->requestText($request, 'contact_function'))
            ->setEmail($this->requestText($request, 'contact_email'))
            ->setPhone($this->requestText($request, 'contact_phone'))
            ->setNotes($this->requestText($request, 'contact_notes'));

        $assignment->setContactPerson($contactPerson);

        return true;
    }

    private function requestText(Request $request, string $name): ?string
    {
        $value = trim((string) $request->request->get($name, ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return array{valid: bool, contact_person: ?OrganizationContact}
     */
    private function contactPersonAssignment(Actor $actor, Request $request): array
    {
        if ($actor->getType() !== Actor::TYPE_ORGANIZATION) {
            return ['valid' => true, 'contact_person' => null];
        }

        $contactChoice = (string) $request->request->get('contact_person_id', '');

        if ($contactChoice === '') {
            return ['valid' => true, 'contact_person' => null];
        }

        if ($contactChoice !== '__new__') {
            $contactPersonId = $this->positiveInt($contactChoice);

            if ($contactPersonId === null) {
                return ['valid' => false, 'contact_person' => null];
            }

            $contactPerson = $this->entityManager
                ->getRepository(OrganizationContact::class)
                ->find($contactPersonId);

            if ($contactPerson instanceof OrganizationContact && $contactPerson->getOrganization()->getId() === $actor->getId()) {
                return ['valid' => true, 'contact_person' => $contactPerson];
            }

            return ['valid' => false, 'contact_person' => null];
        }

        $contactName = mb_substr(trim((string) $request->request->get('contact_name', '')), 0, 255);
        $contactFunction = mb_substr(trim((string) $request->request->get('contact_function', '')), 0, 255);
        $contactEmail = mb_substr(trim((string) $request->request->get('contact_email', '')), 0, 180);
        $contactPhone = mb_substr(trim((string) $request->request->get('contact_phone', '')), 0, 100);

        if ($contactName === '') {
            return ['valid' => false, 'contact_person' => null];
        }

        $contactPerson = $this->organizationContactForName($actor, $contactName);

        if (!$contactPerson instanceof OrganizationContact) {
            return ['valid' => false, 'contact_person' => null];
        }

        $contactPerson
            ->setFunctionTitle($contactFunction !== '' ? $contactFunction : null)
            ->setEmail($contactEmail !== '' ? $contactEmail : null)
            ->setPhone($contactPhone !== '' ? $contactPhone : null);

        return ['valid' => true, 'contact_person' => $contactPerson];
    }

    /**
     * @return list<Actor>
     */
    private function actorOptions(): array
    {
        return $this->entityManager
            ->getRepository(Actor::class)
            ->createQueryBuilder('actor')
            ->orderBy('actor.alias', 'ASC')
            ->addOrderBy('actor.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<OrganizationContact>
     */
    private function contactOptions(): array
    {
        return $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->createQueryBuilder('contact')
            ->addSelect('organization', 'person')
            ->join('contact.organization', 'organization')
            ->join('contact.person', 'person')
            ->orderBy('organization.alias', 'ASC')
            ->addOrderBy('organization.name', 'ASC')
            ->addOrderBy('person.alias', 'ASC')
            ->addOrderBy('person.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function organizationContactForName(Actor $organization, string $name): ?OrganizationContact
    {
        $person = $this->entityManager->getRepository(Actor::class)->findOneBy(['name' => $name]);

        if ($person instanceof Actor && $person->getType() !== Actor::TYPE_PERSON) {
            return null;
        }

        if (!$person instanceof Actor) {
            $person = new Actor($name, Actor::TYPE_PERSON);
            $this->entityManager->persist($person);
        }

        $contact = $person->getId() === null ? null : $this->entityManager
            ->getRepository(OrganizationContact::class)
            ->findOneBy([
                'organization' => $organization,
                'person' => $person,
            ]);

        if (!$contact instanceof OrganizationContact) {
            $contact = new OrganizationContact($organization, $person);
            $this->entityManager->persist($contact);
        }

        return $contact;
    }

    private function positiveInt(mixed $value): ?int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($intValue) && $intValue > 0 ? $intValue : null;
    }

    private function projectObjectForProject(int $projectId, int $projectObjectId): ProjectObject
    {
        $projectObject = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->find($projectObjectId);

        if (!$projectObject instanceof ProjectObject || $projectObject->getProject()->getId() !== $projectId) {
            throw $this->createNotFoundException();
        }

        return $projectObject;
    }

    /**
     * @param list<ProjectObject> $projectObjects
     * @return list<ObjectRecord>
     */
    private function objectsFromProjectObjects(array $projectObjects): array
    {
        return array_map(
            static fn (ProjectObject $projectObject): ObjectRecord => $projectObject->getObjectRecord(),
            $projectObjects,
        );
    }

    /**
     * @return array<int, list<ReportSeries>>
     */
    private function reportSeriesByObject(Project $project): array
    {
        $seriesList = $this->entityManager
            ->getRepository(ReportSeries::class)
            ->createQueryBuilder('series')
            ->addSelect('objectRecord')
            ->join('series.objectRecord', 'objectRecord')
            ->andWhere('series.project = :project')
            ->setParameter('project', $project)
            ->orderBy('objectRecord.inventoryNumber', 'ASC')
            ->addOrderBy('series.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($seriesList as $series) {
            $objectId = $series->getObjectRecord()->getId();

            if ($objectId !== null) {
                $grouped[$objectId][] = $series;
            }
        }

        return $grouped;
    }

    /**
     * @param list<ProjectObject> $projectObjects
     */
    private function ensureReportSeriesForProjectObjects(array $projectObjects): void
    {
        $created = false;

        foreach ($projectObjects as $projectObject) {
            $created = $this->ensureReportSeriesForProjectObject($projectObject) || $created;
        }

        if ($created) {
            $this->entityManager->flush();
        }
    }

    private function ensureReportSeriesForProjectObject(ProjectObject $projectObject): bool
    {
        $object = $projectObject->getObjectRecord();
        $project = $projectObject->getProject();

        $existingSeries = $this->entityManager
            ->getRepository(ReportSeries::class)
            ->findOneBy([
                'objectRecord' => $object,
                'project' => $project,
            ]);

        if ($existingSeries instanceof ReportSeries) {
            return false;
        }

        $series = new ReportSeries($object, $object->getInventoryNumber(), $project);
        $this->entityManager->persist($series);

        return true;
    }

    /**
     * @return array<int, list<Report>>
     */
    private function reportsBySeries(Project $project): array
    {
        $reports = $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->addSelect('series')
            ->join('report.series', 'series')
            ->andWhere('series.project = :project')
            ->setParameter('project', $project)
            ->orderBy('report.createdAt', 'ASC')
            ->addOrderBy('report.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($reports as $report) {
            $seriesId = $report->getSeries()->getId();

            if ($seriesId !== null) {
                $grouped[$seriesId][] = $report;
            }
        }

        return $grouped;
    }

    /**
     * @param array<int, list<Report>> $reportsBySeries
     * @return array<int, string>
     */
    private function reportAuthorNames(array $reportsBySeries): array
    {
        $userIds = [];

        foreach ($reportsBySeries as $reports) {
            foreach ($reports as $report) {
                $userId = $report->getCreatedById();

                if ($userId !== null) {
                    $userIds[$userId] = $userId;
                }
            }
        }

        if ($userIds === []) {
            return [];
        }

        $users = $this->entityManager
            ->getRepository(User::class)
            ->createQueryBuilder('user')
            ->andWhere('user.id IN (:ids)')
            ->setParameter('ids', array_values($userIds))
            ->getQuery()
            ->getResult();

        $usersById = [];

        foreach ($users as $user) {
            $name = $user->getFullName() !== '' ? $user->getFullName() : $user->getEmail();
            $usersById[$user->getId()] = $name;
        }

        $authorNames = [];

        foreach ($reportsBySeries as $reports) {
            foreach ($reports as $report) {
                $reportId = $report->getId();
                $userId = $report->getCreatedById();

                if ($reportId !== null && $userId !== null && isset($usersById[$userId])) {
                    $authorNames[$reportId] = $usersById[$userId];
                }
            }
        }

        return $authorNames;
    }
}
