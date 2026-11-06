<?php
/**
 * Generates a large set of test users and contacts for performance testing.
 *
 * Rows are written straight to the database in batches: going through the API would take days at this
 * volume. All users, address books and contacts belong to tenants named "<prefix>-1", "<prefix>-2", ...
 * so that --cleanup can remove exactly what was generated. E-mail addresses use reserved .example domains.
 *
 * Usage:
 *   php dev/generate-test-data.php                                  Show the plan, write nothing
 *   php dev/generate-test-data.php --run                            Generate 100 000 users, 1 000 000 contacts
 *   php dev/generate-test-data.php --run --users=200 --contacts=2000
 *   php dev/generate-test-data.php --stats                          Show what is currently generated
 *   php dev/generate-test-data.php --cleanup --run                  Remove everything that was generated
 *
 * Options:
 *   --users=N      Number of users, 100000 by default
 *   --contacts=N   Approximate number of personal and collected contacts, 1000000 by default.
 *                  Each user also gets a card in the Team address book of the tenant
 *   --tenants=N    Number of tenants, 2 by default. The first one gets 70% of the users
 *   --prefix=NAME  Prefix of the tenant names and of the logins, "loadtest" by default
 *   --seed=N       Seed of the random generator, makes names and sizes repeatable
 *   --mail-accounts=LIST  Comma separated e-mails offered as logins of the first users,
 *                         test1@afterlogic.com,test2@afterlogic.com by default
 *
 * With --run the script asks whether the first users should get mail accounts, so that it is possible to
 * log in as them. The accounts are stored without a password: the first login checks the entered password
 * on the mail server and saves it. If users with these e-mails already exist, the script offers to delete
 * them and stops if the answer is no.
 */

use Afterlogic\DAV\Constants;
use Aurora\Modules\Contacts\Enums\Access;
use Aurora\Modules\Contacts\Enums\PrimaryEmail;
use Aurora\Modules\Core\Module as CoreModule;
use Aurora\Modules\Mail\Models\MailAccount;
use Aurora\Modules\Mail\Module as MailModule;
use Aurora\System\Api;
use Illuminate\Database\Capsule\Manager as Capsule;
use Sabre\DAV\UUIDUtil;

if (PHP_SAPI !== 'cli') {
    exit("Use the console for running this script\n");
}

require_once \dirname(__file__) . '/../system/autoload.php';
Api::Init(true);

const USERS_PER_BATCH = 500;
const ROWS_PER_INSERT = 1000;
const SHARED_BOOK_PERCENT = 1;
const COLLECTED_CONTACT_PERCENT = 30;
const FIRST_TENANT_PERCENT = 70;
const CONTACT_AGE_SECONDS = 3 * 365 * 86400;

const FIRST_NAMES = [
    'Andrei', 'Ana', 'Alexandru', 'Maria', 'Mihai', 'Elena', 'Ion', 'Ioana', 'Stefan', 'Cristina',
    'Vlad', 'Daniela', 'Radu', 'Gabriela', 'Bogdan', 'Monica', 'Florin', 'Simona', 'Adrian', 'Laura',
    'Marius', 'Roxana', 'Cosmin', 'Alina', 'Dan', 'Irina', 'George', 'Diana', 'Victor', 'Oana',
];
const LAST_NAMES = [
    'Popescu', 'Ionescu', 'Popa', 'Dumitru', 'Stan', 'Stoica', 'Gheorghe', 'Rusu', 'Munteanu', 'Matei',
    'Constantin', 'Serban', 'Moldovan', 'Lazar', 'Ciobanu', 'Florea', 'Dinu', 'Sava', 'Tabarcea', 'Barbu',
    'Nistor', 'Oprea', 'Radu', 'Tudor', 'Ursu', 'Voicu', 'Zamfir', 'Neagu', 'Marin', 'Ilie',
];
const COMPANIES = [
    'Acme Logistic', 'Carpat Soft', 'Delta Energy', 'Dunarea Trans', 'Nord Consulting',
    'Orion Media', 'Prima Construct', 'Sigma Foods', 'Transilvania Tech', 'Vest Finance',
];

function getOptions()
{
    $options = getopt('', ['run', 'stats', 'cleanup', 'users:', 'contacts:', 'tenants:', 'prefix:', 'seed:', 'mail-accounts:']);

    $result = [
        'run' => isset($options['run']),
        'stats' => isset($options['stats']),
        'cleanup' => isset($options['cleanup']),
        'users' => (int) ($options['users'] ?? 100000),
        'contacts' => (int) ($options['contacts'] ?? 1000000),
        'tenants' => (int) ($options['tenants'] ?? 2),
        'prefix' => (string) ($options['prefix'] ?? 'loadtest'),
        'seed' => isset($options['seed']) ? (int) $options['seed'] : null,
        'mailAccounts' => array_filter(array_map('trim', explode(',', strtolower(
            (string) ($options['mail-accounts'] ?? 'test1@afterlogic.com,test2@afterlogic.com')
        )))),
    ];

    if (!preg_match('/^[a-z][a-z0-9]{2,30}$/', $result['prefix'])) {
        exit("--prefix must be 3-31 lowercase letters and digits and start with a letter\n");
    }
    if ($result['users'] < 1 || $result['contacts'] < 0 || $result['tenants'] < 1) {
        exit("--users and --tenants must be positive, --contacts must not be negative\n");
    }

    return $result;
}

function confirm($question)
{
    echo $question . ' [y/N] ';

    return in_array(strtolower(trim((string) fgets(STDIN))), ['y', 'yes'], true);
}

function deleteUser($userId)
{
    return CoreModule::Decorator()->DeleteUser((int) $userId);
}

/**
 * Asks which mail accounts should be created and clears the way for them.
 *
 * @return array Logins of the first users and the id of the mail server, the logins are empty if not needed
 */
function prepareMailAccounts(array $options)
{
    $emails = array_slice($options['mailAccounts'], 0, $options['users']);
    if (count($emails) === 0 || !confirm('Link mail accounts ' . implode(', ', $emails) . ' to the first users?')) {
        return ['Emails' => [], 'ServerIds' => []];
    }

    $serverIds = [];
    foreach ($emails as $email) {
        $domain = substr(strrchr($email, '@'), 1);
        $result = MailModule::Decorator()->GetMailServerByDomain($domain, true);
        if (!isset($result['Server'])) {
            exit("There is no mail server for the domain $domain, add it in the admin panel first
");
        }
        $serverIds[$email] = (int) $result['Server']->Id;
    }

    $users = Capsule::table('core_users')
        ->whereIn('PublicId', $emails)
        ->orWhereIn('Id', Capsule::table('mail_accounts')->whereIn('Email', $emails)->pluck('IdUser')->all())
        ->get(['Id', 'PublicId']);

    foreach ($users as $user) {
        if (!confirm("User {$user->PublicId} (id {$user->Id}) already has one of these e-mails. Delete this user with all its data?")) {
            exit("Stopped, nothing was generated
");
        }
        deleteUser($user->Id);
        echo "User {$user->PublicId} deleted
";
    }

    return ['Emails' => $emails, 'ServerIds' => $serverIds];
}

function createMailAccount($email, $serverId)
{
    $account = new MailAccount();
    $account->IdUser = (int) Capsule::table('core_users')->where('PublicId', $email)->value('Id');
    $account->Email = $email;
    $account->IncomingLogin = $email;
    $account->setPassword('');
    $account->ServerId = $serverId;
    $account->UseToAuthorize = true;
    $account->save();
}

function getTenantDomain($prefix, $tenantNumber)
{
    return $prefix . $tenantNumber . '.example';
}

function getGeneratedTenants($prefix)
{
    return Capsule::table('core_tenants')->where('Name', 'like', $prefix . '-%')->orderBy('Id')->get(['Id', 'Name'])
        ->filter(function ($tenant) use ($prefix) {
            return preg_match('/^' . $prefix . '-\d+$/', $tenant->Name) === 1;
        })->values();
}

function getTeamPrincipal($tenantId)
{
    return Constants::PRINCIPALS_PREFIX . $tenantId . '_' . Constants::DAV_TENANT_PRINCIPAL;
}

function insertRows($table, array $rows)
{
    foreach (array_chunk($rows, ROWS_PER_INSERT) as $chunk) {
        Capsule::table($table)->insert($chunk);
    }
}

function randomItem(array $items)
{
    return $items[mt_rand(0, count($items) - 1)];
}

/**
 * Most users have few contacts and some have a lot of them, the mean value is $average.
 */
function randomContactsCount($average)
{
    return (int) round(-log(1 - mt_rand(0, mt_getrandmax() - 1) / mt_getrandmax()) * $average);
}

function buildVCard($uid, array $contact)
{
    $emailType = $contact['PrimaryEmail'] === PrimaryEmail::Business ? 'WORK' : 'HOME';
    $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'PRODID:-//Aurora test data//EN', 'UID:' . $uid];

    if ($contact['FullName'] !== '') {
        $lines[] = 'FN:' . $contact['FullName'];
        $lines[] = 'N:' . $contact['LastName'] . ';' . $contact['FirstName'] . ';;;';
    }
    $lines[] = 'EMAIL;TYPE=' . $emailType . ',PREF:' . $contact['ViewEmail'];
    if ($contact['BusinessCompany'] !== '') {
        $lines[] = 'ORG:' . $contact['BusinessCompany'];
    }
    if ($contact['Frequency'] > 0) {
        $lines[] = 'X-FREQUENCY:' . $contact['Frequency'];
    }
    $lines[] = 'END:VCARD';

    return implode("\r\n", $lines) . "\r\n";
}

function buildContact($email, $firstName, $lastName, $company, $frequency, $primaryEmail)
{
    return [
        'FullName' => trim($firstName . ' ' . $lastName),
        'FirstName' => $firstName,
        'LastName' => $lastName,
        'PrimaryEmail' => $primaryEmail,
        'ViewEmail' => $email,
        'PersonalEmail' => $primaryEmail === PrimaryEmail::Personal ? $email : '',
        'BusinessEmail' => $primaryEmail === PrimaryEmail::Business ? $email : '',
        'BusinessCompany' => $company,
        'Frequency' => $frequency,
    ];
}

function buildRandomContact($contactNumber, $collected)
{
    $firstName = randomItem(FIRST_NAMES);
    $lastName = randomItem(LAST_NAMES);
    $company = mt_rand(0, 3) === 0 ? randomItem(COMPANIES) : '';
    $email = strtolower($firstName . '.' . $lastName) . $contactNumber . '@mail' . mt_rand(1, 200) . '.example';

    return $collected
        ? buildContact($email, '', '', '', mt_rand(1, 50), PrimaryEmail::Personal)
        : buildContact($email, $firstName, $lastName, $company, mt_rand(0, 9) === 0 ? mt_rand(1, 50) : 0, PrimaryEmail::Personal);
}

/**
 * @param array $contacts List of [address book id, contact]
 */
function insertContacts(array $contacts, $now)
{
    foreach (array_chunk($contacts, ROWS_PER_INSERT) as $chunk) {
        $cards = [];
        foreach ($chunk as $key => list($addressBookId, $contact)) {
            $uid = UUIDUtil::getUUID();
            $cardData = buildVCard($uid, $contact);
            $cards[$uid . '.vcf'] = [
                'addressbookid' => $addressBookId,
                'carddata' => $cardData,
                'uri' => $uid . '.vcf',
                'lastmodified' => $now - mt_rand(0, CONTACT_AGE_SECONDS),
                'etag' => md5($cardData),
                'size' => strlen($cardData),
            ];
            $chunk[$key][2] = $uid . '.vcf';
        }
        Capsule::table('adav_cards')->insert(array_values($cards));

        $cardIds = Capsule::table('adav_cards')->whereIn('uri', array_keys($cards))->pluck('id', 'uri');

        $rows = [];
        foreach ($chunk as list($addressBookId, $contact, $uri)) {
            $rows[] = array_merge($contact, ['CardId' => $cardIds[$uri], 'AddressBookId' => $addressBookId]);
        }
        Capsule::table('contacts_cards')->insert($rows);
    }
}

function createTenants(array $options)
{
    $channelId = (int) Capsule::table('core_channels')->orderBy('Id')->value('Id');

    $tenants = [];
    for ($number = 1; $number <= $options['tenants']; $number++) {
        $tenantId = Capsule::table('core_tenants')->insertGetId([
            'IdChannel' => $channelId,
            'Name' => $options['prefix'] . '-' . $number,
            'Description' => 'Generated test data',
        ], 'Id');

        $teamBookId = Capsule::table('adav_addressbooks')->insertGetId([
            'principaluri' => getTeamPrincipal($tenantId),
            'displayname' => Constants::ADDRESSBOOK_TEAM_DISPLAY_NAME,
            'uri' => Constants::ADDRESSBOOK_TEAM_NAME,
        ]);

        $tenants[$number] = ['Id' => $tenantId, 'TeamBookId' => $teamBookId, 'Number' => $number];
    }

    return $tenants;
}

function pickTenant(array $tenants, $userNumber, $usersCount)
{
    $tenantsCount = count($tenants);
    if ($tenantsCount === 1) {
        return $tenants[1];
    }

    $firstTenantUsers = (int) ceil($usersCount * FIRST_TENANT_PERCENT / 100);
    if ($userNumber <= $firstTenantUsers) {
        return $tenants[1];
    }

    return $tenants[2 + ($userNumber % ($tenantsCount - 1))];
}

function generateUsersBatch(array $options, array $tenants, array $mailAccounts, $firstUser, $lastUser, $averageContacts, &$counters)
{
    $now = time();
    $users = [];
    $books = [];
    $bookOwners = [];
    for ($userNumber = $firstUser; $userNumber <= $lastUser; $userNumber++) {
        $tenant = pickTenant($tenants, $userNumber, $options['users']);
        $email = $mailAccounts['Emails'][$userNumber - 1]
            ?? $options['prefix'] . '.user' . $userNumber . '@' . getTenantDomain($options['prefix'], $tenant['Number']);
        $principal = Constants::PRINCIPALS_PREFIX . $email;

        $users[] = ['UUID' => UUIDUtil::getUUID(), 'PublicId' => $email, 'IdTenant' => $tenant['Id']];
        $books[] = [
            'principaluri' => $principal,
            'displayname' => Constants::ADDRESSBOOK_DEFAULT_DISPLAY_NAME,
            'uri' => Constants::ADDRESSBOOK_DEFAULT_NAME,
        ];
        $books[] = [
            'principaluri' => $principal,
            'displayname' => Constants::ADDRESSBOOK_COLLECTED_DISPLAY_NAME,
            'uri' => Constants::ADDRESSBOOK_COLLECTED_NAME,
        ];
        $bookOwners[$principal] = ['Email' => $email, 'Tenant' => $tenant];
    }
    insertRows('core_users', $users);
    insertRows('adav_addressbooks', $books);

    foreach ($mailAccounts['Emails'] as $index => $email) {
        if ($index + 1 >= $firstUser && $index + 1 <= $lastUser) {
            createMailAccount($email, $mailAccounts['ServerIds'][$email]);
        }
    }

    $bookIds = [];
    $rows = Capsule::table('adav_addressbooks')->whereIn('principaluri', array_keys($bookOwners))->get(['id', 'principaluri', 'uri']);
    foreach ($rows as $row) {
        $bookIds[$row->principaluri][$row->uri] = (int) $row->id;
    }

    $contacts = [];
    $shares = [];
    $principals = array_keys($bookOwners);
    foreach ($bookOwners as $principal => $owner) {
        $contacts[] = [
            $owner['Tenant']['TeamBookId'],
            buildContact($owner['Email'], '', '', '', 0, PrimaryEmail::Business),
        ];
        $counters['team']++;

        $personalBookId = $bookIds[$principal][Constants::ADDRESSBOOK_DEFAULT_NAME];
        $collectedBookId = $bookIds[$principal][Constants::ADDRESSBOOK_COLLECTED_NAME];

        $contactsCount = randomContactsCount($averageContacts);
        for ($i = 0; $i < $contactsCount; $i++) {
            $collected = mt_rand(1, 100) <= COLLECTED_CONTACT_PERCENT;
            $counters['contacts']++;
            $contacts[] = [$collected ? $collectedBookId : $personalBookId, buildRandomContact($counters['contacts'], $collected)];
        }

        if (count($principals) > 1 && mt_rand(1, 100) <= SHARED_BOOK_PERCENT) {
            do {
                $sharedWith = randomItem($principals);
            } while ($sharedWith === $principal);

            $shares[] = [
                'principaluri' => $sharedWith,
                'access' => Access::Read,
                'addressbook_id' => $personalBookId,
                'addressbookuri' => UUIDUtil::getUUID(),
            ];
            $counters['shares']++;
        }
    }

    insertContacts($contacts, $now);
    insertRows('adav_shared_addressbooks', $shares);
}

function generate(array $options)
{
    if (getGeneratedTenants($options['prefix'])->isNotEmpty()) {
        exit("Tenants with prefix \"{$options['prefix']}\" already exist. Run --cleanup --run or use another --prefix\n");
    }
    if ($options['seed'] !== null) {
        mt_srand($options['seed']);
    }

    $mailAccounts = prepareMailAccounts($options);

    $startedAt = microtime(true);
    $tenants = createTenants($options);
    $averageContacts = $options['contacts'] / $options['users'];
    $counters = ['team' => 0, 'contacts' => 0, 'shares' => 0];

    for ($firstUser = 1; $firstUser <= $options['users']; $firstUser += USERS_PER_BATCH) {
        $lastUser = min($firstUser + USERS_PER_BATCH - 1, $options['users']);

        // A failed batch is rolled back as a whole, --cleanup removes the batches done before it
        Capsule::connection()->transaction(function () use ($options, $tenants, $mailAccounts, $firstUser, $lastUser, $averageContacts, &$counters) {
            generateUsersBatch($options, $tenants, $mailAccounts, $firstUser, $lastUser, $averageContacts, $counters);
        });

        printf(
            "%d/%d users, %d contacts, %d s\n",
            $lastUser,
            $options['users'],
            $counters['contacts'] + $counters['team'],
            microtime(true) - $startedAt
        );
    }

    printf(
        "Done: %d users, %d personal and collected contacts, %d team contacts, %d shared address books\n",
        $options['users'],
        $counters['contacts'],
        $counters['team'],
        $counters['shares']
    );
}

function deleteAddressBooks(array $bookIds)
{
    if (count($bookIds) === 0) {
        return;
    }

    do {
        $deleted = Capsule::table('contacts_cards')->whereIn('AddressBookId', $bookIds)->limit(10000)->delete();
    } while ($deleted > 0);
    do {
        $deleted = Capsule::table('adav_cards')->whereIn('addressbookid', $bookIds)->limit(10000)->delete();
    } while ($deleted > 0);

    Capsule::table('adav_shared_addressbooks')->whereIn('addressbook_id', $bookIds)->delete();
    Capsule::table('adav_addressbooks')->whereIn('id', $bookIds)->delete();
}

function cleanup(array $options)
{
    $tenants = getGeneratedTenants($options['prefix']);
    foreach ($tenants as $tenant) {
        // These users could have logged in and own more data than the generated rows
        $mailUserIds = Capsule::table('mail_accounts')
            ->join('core_users', 'core_users.Id', '=', 'mail_accounts.IdUser')
            ->where('core_users.IdTenant', $tenant->Id)
            ->pluck('core_users.Id')->unique();
        foreach ($mailUserIds as $userId) {
            deleteUser($userId);
        }

        $removedUsers = 0;
        do {
            $users = Capsule::table('core_users')->where('IdTenant', $tenant->Id)->limit(USERS_PER_BATCH)->get(['Id', 'PublicId']);
            if ($users->isNotEmpty()) {
                $principals = $users->map(function ($user) {
                    return Constants::PRINCIPALS_PREFIX . $user->PublicId;
                })->all();

                deleteAddressBooks(Capsule::table('adav_addressbooks')->whereIn('principaluri', $principals)->pluck('id')->all());
                Capsule::table('adav_shared_addressbooks')->whereIn('principaluri', $principals)->delete();
                Capsule::table('core_users')->whereIn('Id', $users->pluck('Id')->all())->delete();

                $removedUsers += $users->count();
                echo "{$tenant->Name}: $removedUsers users removed\n";
            }
        } while ($users->isNotEmpty());

        deleteAddressBooks(Capsule::table('adav_addressbooks')->where('principaluri', getTeamPrincipal($tenant->Id))->pluck('id')->all());
        Capsule::table('core_tenants')->where('Id', $tenant->Id)->delete();
        echo "{$tenant->Name}: tenant removed\n";
    }

    echo $tenants->isEmpty() ? "Nothing to remove\n" : "Done\n";
}

function showStats(array $options)
{
    $tenants = getGeneratedTenants($options['prefix']);
    foreach ($tenants as $tenant) {
        $teamBookId = Capsule::table('adav_addressbooks')->where('principaluri', getTeamPrincipal($tenant->Id))->value('id');

        printf(
            "%s: %d users, %d contacts in the Team address book\n",
            $tenant->Name,
            Capsule::table('core_users')->where('IdTenant', $tenant->Id)->count(),
            Capsule::table('contacts_cards')->where('AddressBookId', $teamBookId)->count()
        );
    }

    printf(
        "Whole database: %d users, %d address books, %d cards, %d contacts\n",
        Capsule::table('core_users')->count(),
        Capsule::table('adav_addressbooks')->count(),
        Capsule::table('adav_cards')->count(),
        Capsule::table('contacts_cards')->count()
    );
}

$options = getOptions();

if ($options['stats']) {
    showStats($options);
} elseif ($options['cleanup']) {
    if ($options['run']) {
        cleanup($options);
    } else {
        echo 'Would remove tenants: ' . getGeneratedTenants($options['prefix'])->pluck('Name')->implode(', ') . "\nAdd --run to remove them\n";
    }
} elseif ($options['run']) {
    generate($options);
} else {
    printf(
        "Would create %d tenants \"%s-N\", %d users and about %d contacts plus %d team contacts.\nAdd --run to generate them\n",
        $options['tenants'],
        $options['prefix'],
        $options['users'],
        $options['contacts'],
        $options['users']
    );
}
