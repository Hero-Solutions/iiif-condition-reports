<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Actor;
use App\Entity\OrganizationContact;
use App\Entity\IIIFManifest;
use App\Entity\ObjectManifest;
use App\Entity\Project;
use App\Entity\ProjectActor;
use App\Entity\ProjectObject;
use App\Entity\ProjectObjectActor;
use App\Entity\Report;
use App\Entity\ReportActor;
use App\Entity\ReportManifest;
use App\Entity\ReportSeries;
use App\Entity\User;
use App\Service\ObjectThumbnailProvider;
use App\Service\ReportFormDefinition;
use App\Value\ActorRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReportFormDefinition $formDefinition,
        private readonly ObjectThumbnailProvider $thumbnailProvider,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/{_locale<nl|en>}/report-series/{seriesId}/reports/new', name: 'reports_new', methods: ['GET', 'POST'])]
    public function new(int $seriesId, Request $request): Response
    {
        $series = $this->entityManager->getRepository(ReportSeries::class)->find($seriesId);

        if (!$series instanceof ReportSeries) {
            throw $this->createNotFoundException();
        }

        $previousReport = $this->latestReportForSeries($series);
        $report = new Report($series, $previousReport?->getType() ?? Report::TYPE_INCOMING_CONDITION);
        $user = $this->getUser();

        if ($user instanceof User) {
            $report->setCreatedById($user->getId());
        }

        if ($previousReport instanceof Report) {
            $report
                ->setBasedOnReport($previousReport)
                ->setCustomType($previousReport->getCustomType())
                ->setTitle($previousReport->getTitle())
                ->setDescription($previousReport->getDescription())
                ->setStartedAt($previousReport->getStartedAt())
                ->setEndedAt($previousReport->getEndedAt())
                ->setData($previousReport->getData());
        } else {
            $report
                ->setTitle($series->getTitle() !== '' ? $series->getTitle() : $this->translator->trans('report_type.incoming_condition'))
                ->setDescription($series->getDescription())
                ->setStartedAt($series->getStartedAt())
                ->setEndedAt($series->getEndedAt());
        }

        $this->entityManager->persist($report);

        $copiedActorKeys = [];

        if ($previousReport instanceof Report) {
            $this->copyReportActors($previousReport, $report, $copiedActorKeys);
        }

        $this->copyProjectActors($report, $copiedActorKeys);
        $this->copyProjectObjectActors($report, $copiedActorKeys);
        $this->entityManager->flush();

        return $this->redirectToRoute('reports_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/edit', name: 'reports_edit', methods: ['GET', 'POST'])]
    public function edit(Report $report, Request $request): Response
    {
        return $this->handleReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/autosave', name: 'reports_autosave', methods: ['POST'])]
    public function autosave(Report $report, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('report_edit_' . $report->getId(), (string) $request->request->get('_token'))) {
            return new JsonResponse(['saved' => false], Response::HTTP_FORBIDDEN);
        }

        $this->applySubmittedReport($report, $request);
        $this->entityManager->flush();

        return new JsonResponse([
            'saved' => true,
            'saved_at' => $report->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{_locale<nl|en>}/reports/{id}/delete', name: 'reports_delete', methods: ['POST'])]
    public function delete(Report $report, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('report_delete_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $backUrl = $this->backUrl($report, $request);

        if ($report->getStatus() !== Report::STATUS_ACTIVE) {
            $this->addFlash('error', 'reports.delete_draft_only');

            return $this->redirect($backUrl);
        }

        $this->entityManager->remove($report);
        $this->entityManager->flush();
        $this->addFlash('success', 'reports.deleted');

        return $this->redirect($backUrl);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/add', name: 'reports_actors_add', methods: ['POST'])]
    public function addReportActor(int $reportId, Request $request): Response
    {
        $report = $this->reportForId($reportId);

        if (!$this->isCsrfTokenValid('report_actor_add_' . $report->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $actorData = $this->actorAssignmentData($request);

        if ($actorData === null) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $actor = $this->findOrCreateActor($actorData);

        if (!$actor instanceof Actor) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $reportActor = new ReportActor($report, $actor);
        $reportActor
            ->setRole($actorData['role'])
            ->setCustomRole($actorData['custom_role'])
            ->normalizeCustomRole();

        $this->entityManager->persist($reportActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.added');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/remove', name: 'reports_actors_remove', methods: ['POST'])]
    public function removeReportActor(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_remove_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->entityManager->remove($reportActor);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.removed');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/edit', name: 'reports_actors_edit_assignment', methods: ['POST'])]
    public function editReportActorAssignment(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_edit_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->updateActorAssignment($reportActor, $request)) {
            $this->addFlash('error', 'actors.invalid');

            return $this->redirectToReport($report, $request);
        }

        $this->entityManager->flush();
        $this->addFlash('success', 'actors.saved');

        return $this->redirectToReport($report, $request);
    }

    #[Route('/{_locale<nl|en>}/reports/{reportId}/actors/{id}/contact', name: 'reports_actors_contact', methods: ['POST'])]
    public function updateReportActorContact(int $reportId, ReportActor $reportActor, Request $request): Response
    {
        $report = $this->reportForId($reportId);

        if ($reportActor->getReport()->getId() !== $report->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('report_actor_contact_' . $reportActor->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $contactResult = $this->contactPersonAssignment($reportActor->getActor(), $request);

        if (!$contactResult['valid']) {
            $this->addFlash('error', 'actors.invalid_contact');

            return $this->redirectToReport($report, $request);
        }

        $reportActor->setContactPerson($contactResult['contact_person']);
        $this->entityManager->flush();
        $this->addFlash('success', 'actors.contact_saved');

        return $this->redirectToReport($report, $request);
    }

    private function handleReport(Report $report, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $tokenId = 'report_edit_' . $report->getId();

            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            $this->applySubmittedReport($report, $request);
            $this->entityManager->flush();
            $this->addFlash('success', 'reports.saved');

            return $this->redirectToReport($report, $request);
        }

        return $this->render('reports/form.html.twig', [
            'report' => $report,
            'series' => $report->getSeries(),
            'object' => $report->getObjectRecord(),
            'definition' => $this->formDefinition->forObject($report->getObjectRecord()),
            'report_types' => Report::typeChoices(),
            'report_statuses' => Report::statusChoices(),
            'report_actors' => $this->reportActors($report),
            'actor_type_choices' => Actor::typeChoices(),
            'actor_role_choices' => ActorRole::choices(),
            'actor_options' => $this->actorOptions(),
            'contact_options' => $this->contactOptions(),
            'is_new' => false,
            'csrf_token_id' => 'report_edit_' . $report->getId(),
            'back_url' => $this->backUrl($report, $request),
            'thumbnail_url' => $this->thumbnailProvider->thumbnailForObject($report->getObjectRecord()),
            'annotation_manifest_url' => $this->annotationManifestUrl($report),
        ]);
    }

    private function applySubmittedReport(Report $report, Request $request): void
    {
        $type = (string) $request->request->get('type', Report::TYPE_OTHER);
        $customType = mb_substr(trim((string) $request->request->get('custom_type', '')), 0, 100);
        $status = (string) $request->request->get('status', Report::STATUS_ACTIVE);
        $title = trim((string) $request->request->get('title', ''));
        $data = $request->request->all('report_data');

        $report
            ->setType($type)
            ->setCustomType($customType);
        $report->setStatus($status);
        $defaultTitle = $report->getType() === Report::TYPE_OTHER && $report->getCustomType()
            ? $report->getCustomType()
            : $this->translator->trans('report_type.' . $report->getType());
        $report->setTitle($title !== '' ? $title : $defaultTitle);
        $report->setDescription($this->requestText($request, 'description'));
        $report->setStartedAt($this->requestDate($request, 'started_at'));
        $report->setEndedAt($this->requestDate($request, 'ended_at'));
        $report->setData($data);
    }

    private function latestReportForSeries(ReportSeries $series): ?Report
    {
        return $this->entityManager
            ->getRepository(Report::class)
            ->createQueryBuilder('report')
            ->andWhere('report.series = :series')
            ->setParameter('series', $series)
            ->orderBy('report.createdAt', 'DESC')
            ->addOrderBy('report.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyReportActors(Report $sourceReport, Report $targetReport, array &$copiedActorKeys): void
    {
        foreach ($this->reportActors($sourceReport) as $sourceActor) {
            $this->copyActorToReport(
                $targetReport,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyProjectActors(Report $report, array &$copiedActorKeys): void
    {
        $project = $report->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $actors = $this->entityManager
            ->getRepository(ProjectActor::class)
            ->findBy(['project' => $project], ['createdAt' => 'ASC', 'id' => 'ASC']);

        foreach ($actors as $sourceActor) {
            $this->copyActorToReport(
                $report,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyProjectObjectActors(Report $report, array &$copiedActorKeys): void
    {
        $project = $report->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $projectObject = $this->entityManager
            ->getRepository(ProjectObject::class)
            ->findOneBy([
                'project' => $project,
                'objectRecord' => $report->getObjectRecord(),
            ]);

        if (!$projectObject instanceof ProjectObject) {
            return;
        }

        $actors = $this->entityManager
            ->getRepository(ProjectObjectActor::class)
            ->findBy(['projectObject' => $projectObject], ['createdAt' => 'ASC', 'id' => 'ASC']);

        foreach ($actors as $sourceActor) {
            $this->copyActorToReport(
                $report,
                $sourceActor->getActor(),
                $sourceActor->getContactPerson(),
                $sourceActor->getRole(),
                $sourceActor->getCustomRole(),
                $copiedActorKeys,
            );
        }
    }

    /**
     * @param array<string, true> $copiedActorKeys
     */
    private function copyActorToReport(
        Report $report,
        Actor $actor,
        ?OrganizationContact $contactPerson,
        string $role,
        ?string $customRole,
        array &$copiedActorKeys,
    ): void {
        $key = $this->actorCopyKey($actor, $contactPerson, $role, $customRole);

        if (isset($copiedActorKeys[$key])) {
            return;
        }

        $copy = new ReportActor($report, $actor);
        $copy
            ->setContactPerson($contactPerson)
            ->setRole($role)
            ->setCustomRole($customRole)
            ->normalizeCustomRole();

        $this->entityManager->persist($copy);
        $copiedActorKeys[$key] = true;
    }

    private function actorCopyKey(Actor $actor, ?OrganizationContact $contactPerson, string $role, ?string $customRole): string
    {
        return implode('|', [
            (string) $actor->getId(),
            (string) ($contactPerson?->getId() ?? 0),
            $role,
            $customRole ?? '',
        ]);
    }

    /**
     * @return list<ReportActor>
     */
    private function reportActors(Report $report): array
    {
        if ($report->getId() === null) {
            return [];
        }

        return $this->entityManager
            ->getRepository(ReportActor::class)
            ->createQueryBuilder('reportActor')
            ->addSelect('actor', 'contactPerson')
            ->join('reportActor.actor', 'actor')
            ->leftJoin('reportActor.contactPerson', 'contactPerson')
            ->andWhere('reportActor.report = :report')
            ->setParameter('report', $report)
            ->orderBy('reportActor.createdAt', 'ASC')
            ->addOrderBy('reportActor.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    private function reportForId(int $reportId): Report
    {
        $report = $this->entityManager->getRepository(Report::class)->find($reportId);

        if (!$report instanceof Report) {
            throw $this->createNotFoundException();
        }

        return $report;
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

    private function updateActorAssignment(ReportActor $assignment, Request $request): bool
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

    private function requestText(Request $request, string $name): ?string
    {
        $value = trim((string) $request->request->get($name, ''));

        return $value === '' ? null : $value;
    }

    private function requestDate(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get($name, ''));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable ? $date : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        $intValue = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($intValue) && $intValue > 0 ? $intValue : null;
    }

    private function redirectToReport(Report $report, Request $request): Response
    {
        return $this->redirectToRoute('reports_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getId(),
        ]);
    }

    private function backUrl(Report $report, Request $request): string
    {
        $project = $report->getProject();

        if ($project instanceof Project) {
            return $this->generateUrl('projects_edit', [
                '_locale' => $request->getLocale(),
                'id' => $project->getId(),
            ]);
        }

        return $this->generateUrl('objects_edit', [
            '_locale' => $request->getLocale(),
            'id' => $report->getObjectRecord()->getId(),
        ]);
    }

    private function annotationManifestUrl(Report $report): ?string
    {
        if ($report->getId() !== null) {
            $reportLink = $this->entityManager
                ->getRepository(ReportManifest::class)
                ->findOneBy([
                    'report' => $report,
                    'role' => ReportManifest::ROLE_REFERENCE,
                ]);

            if ($reportLink instanceof ReportManifest) {
                return $this->manifestUrl($reportLink->getManifest());
            }
        }

        $objectLink = $this->entityManager
            ->getRepository(ObjectManifest::class)
            ->findOneBy([
                'objectRecord' => $report->getObjectRecord(),
                'role' => ObjectManifest::ROLE_SOURCE,
            ]);

        return $objectLink instanceof ObjectManifest ? $this->manifestUrl($objectLink->getManifest()) : null;
    }

    private function manifestUrl(IIIFManifest $manifest): ?string
    {
        $sourceUrl = $manifest->getSourceUrl();

        if ($sourceUrl !== null && trim($sourceUrl) !== '') {
            return $sourceUrl;
        }

        $manifestId = $manifest->getManifestId();

        return trim($manifestId) !== '' ? $manifestId : null;
    }
}
