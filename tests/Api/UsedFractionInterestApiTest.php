<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Core\Bridge\Symfony\Bundle\Test\ApiTestCase;
use App\DataFixtures\TestFixtures;
use App\Entity\FractionInterest;
use App\Entity\Project;
use App\Entity\Proposal;
use App\Entity\UsedFractionInterest;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManager;
use Vrok\SymfonyAddons\PHPUnit\AuthenticatedClientTrait;
use Vrok\SymfonyAddons\PHPUnit\RefreshDatabaseTrait;

/**
 * @group UsedFractionInterestApi
 */
class UsedFractionInterestApiTest extends ApiTestCase
{
    use AuthenticatedClientTrait;
    use RefreshDatabaseTrait;

    private ?EntityManager $entityManager;

    public static function setUpBeforeClass(): void
    {
        static::$fixtureGroups = ['initial', 'test'];
    }

    public static function tearDownAfterClass(): void
    {
        self::fixtureCleanup();
    }

    /**
     * Test that no collection of usedFractionInterests is available, not even for admins.
     */
    public function testCollectionNotAvailable(): void
    {
        static::createAuthenticatedClient([
            'email' => TestFixtures::ADMIN['email'],
        ])->request('GET', '/used_fraction_interests');

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json');

        self::assertJsonContains([
            '@context'          => '/contexts/Error',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'No route found for "GET /used_fraction_interests": Method Not Allowed (Allow: POST)',
        ]);
    }

    public function testGetUsedFractionInterestAsAdmin(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::ADMIN['email'],
        ]);

        $iri = $this->findIriBy(UsedFractionInterest::class, ['id' => 1]);

        $client->request('GET', $iri);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertMatchesResourceItemJsonSchema(UsedFractionInterest::class);

        self::assertJsonContains([
            '@id' => $iri,
        ]);
    }

    public function testCreate(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROJECT_WRITER['email'],
        ]);
        $argIri = $this->findIriBy(FractionInterest::class,
            ['id' => 1]);
        $propIri = $this->findIriBy(Proposal::class,
            ['id' => TestFixtures::PROPOSAL_2['id']]);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'fractionInterest' => $argIri,
            'proposal' => $propIri,
        ]]);

        self::assertResponseStatusCodeSame(201);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertMatchesResourceItemJsonSchema(UsedFractionInterest::class);

        self::assertJsonContains([
            '@context'  => '/contexts/UsedFractionInterest',
            '@type'     => 'UsedFractionInterest',
            'fractionInterest'  => ['@id' => $argIri],
            'proposal'  => ['@id' => $propIri],
            'createdBy' => [
                'id' => TestFixtures::PROJECT_WRITER['id'],
            ],
        ]);

        /** @var Project $found */
        $em = static::$kernel->getContainer()->get('doctrine')->getManager();
        $found = $em->getRepository(Project::class)
            ->find(TestFixtures::PROJECT['id']);

        // creation of a new (sub-)sub-resource should update the timestamp of the project
        self::assertTrue($now < $found->getUpdatedAt());
    }

    public function testCreateFailsUnauthenticated(): void
    {
        $client = static::createClient();
        $argIri = $this->findIriBy(FractionInterest::class,
            ['id' => 1]);
        $propIri = $this->findIriBy(Proposal::class,
            ['id' => TestFixtures::PROPOSAL_2['id']]);

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'fractionInterest' => $argIri,
            'proposal' => $propIri,
        ]]);
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type',
            'application/json');

        self::assertJsonContains([
            'code'    => 401,
            'message' => 'JWT Token not found',
        ]);
    }

    public function testCreateFailsWithoutPrivilege(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROJECT_OBSERVER['email'],
        ]);
        $argIri = $this->findIriBy(FractionInterest::class,
            ['id' => 1]);
        $propIri = $this->findIriBy(Proposal::class,
            ['id' => TestFixtures::PROPOSAL_2['id']]);

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'fractionInterest' => $argIri,
            'proposal' => $propIri,
        ]]);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertJsonContains([
            '@context'          => '/contexts/Error',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'Access Denied.',
        ]);
    }

    public function testCreateWithoutPropsalFails(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROCESS_MANAGER['email'],
        ]);
        $argIri = $this->findIriBy(FractionInterest::class,
            ['id' => 1]);

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'fractionInterest' => $argIri,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertJsonContains([
            '@context'          => '/contexts/ConstraintViolationList',
            '@type'             => 'ConstraintViolationList',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'proposal: validate.general.notBlank',
        ]);
    }

    public function testCreateWithoutFractionInterestFails(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROCESS_MANAGER['email'],
        ]);
        $propIri = $this->findIriBy(Proposal::class,
            ['id' => TestFixtures::PROPOSAL_2['id']]);

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'proposal' => $propIri,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertJsonContains([
            '@context'          => '/contexts/ConstraintViolationList',
            '@type'             => 'ConstraintViolationList',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'fractionInterest: validate.general.notBlank',
        ]);
    }

    public function testCreateDuplicateFails(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROCESS_MANAGER['email'],
        ]);

        $argIri = $this->findIriBy(FractionInterest::class,
            ['id' => 1]);
        $propIri = $this->findIriBy(Proposal::class,
            ['id' => TestFixtures::PROPOSAL_1['id']]);

        $client->request('POST', '/used_fraction_interests', ['json' => [
            'fractionInterest' => $argIri,
            'proposal' => $propIri,
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertJsonContains([
            '@context'          => '/contexts/ConstraintViolationList',
            '@type'             => 'ConstraintViolationList',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'fractionInterest: validate.proposal.duplicateFractionInterest',
        ]);
    }

    public function testUpdateNotAvailable(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROJECT_COORDINATOR['email'],
        ]);

        $iri = $this->findIriBy(UsedFractionInterest::class, ['id' => 1]);
        $client->request('PUT', $iri, ['json' => [
        ]]);

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json');

        self::assertJsonContains([
            '@context'          => '/contexts/Error',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'No route found for "PUT /used_fraction_interests/1": Method Not Allowed (Allow: GET, DELETE)',
        ]);
    }

    public function testDelete(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROJECT_WRITER['email'],
        ]);

        $em = static::$kernel->getContainer()->get('doctrine')->getManager();
        /** @var FractionInterest $before */
        $before = $em->getRepository(FractionInterest::class)
            ->find(1);
        self::assertCount(1, $before->getUsages());

        $now = new DateTimeImmutable();
        sleep(1);

        $iri = $this->findIriBy(UsedFractionInterest::class, ['id' => 1]);
        $client->request('DELETE', $iri);

        static::assertResponseStatusCodeSame(204);

        /** @var UsedFractionInterest $deleted */
        $deleted = $em->getRepository(UsedFractionInterest::class)
            ->find(1);
        self::assertNull($deleted);

        /** @var FractionInterest $after */
        $after = $em->getRepository(FractionInterest::class)
            ->find(1);
        self::assertCount(0, $after->getUsages());

        // deletion of a (sub-)sub-resource should update the timestamp of the project
        $project = $em->getRepository(Project::class)
            ->find(TestFixtures::PROJECT['id']);
        self::assertTrue($now < $project->getUpdatedAt());
    }

    public function testDeleteFailsUnauthenticated(): void
    {
        $client = static::createClient();
        $iri = $this->findIriBy(UsedFractionInterest::class, ['id' => 1]);
        $client->request('DELETE', $iri);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('content-type',
            'application/json');

        self::assertJsonContains([
            'code'    => 401,
            'message' => 'JWT Token not found',
        ]);
    }

    public function testDeleteFailsWithoutPrivilege(): void
    {
        $client = static::createAuthenticatedClient([
            'email' => TestFixtures::PROJECT_OBSERVER['email'],
        ]);

        $iri = $this->findIriBy(UsedFractionInterest::class, ['id' => 1]);
        $client->request('DELETE', $iri);

        self::assertResponseStatusCodeSame(403);
        self::assertResponseHeaderSame('content-type',
            'application/ld+json; charset=utf-8');

        self::assertJsonContains([
            '@context'          => '/contexts/Error',
            '@type'             => 'hydra:Error',
            'hydra:title'       => 'An error occurred',
            'hydra:description' => 'Access Denied.',
        ]);
    }
}
