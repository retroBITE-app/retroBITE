<?php

use App\Jobs\DiscoverShares;
use App\Jobs\ListShares;
use App\Models\Destination;
use App\Models\User;
use App\Transfers\Discovery\FoundHost;
use App\Transfers\Discovery\Mdns;
use App\Transfers\Discovery\ShareDiscovery;
use App\Transfers\Smb\ShareClient;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Fakes\FolderShareClient;

/*
 * Settings → Destinations: finding a share on the network, listing what a
 * machine offers, saving one. Every question to the network is a job.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/** mDNS that nobody answers, without sending anything. */
function silentMdns(): Mdns
{
    return new class extends Mdns
    {
        public function browse(string $service = '_smb._tcp.local', float $seconds = 2.0): array
        {
            return [];
        }

        public function resolve(string $name, float $seconds = 1.0): ?string
        {
            return null;
        }
    };
}

/** A discovery that finds what it is told to, without touching the network. */
function discoveryFinding(array $hosts, array $names = []): ShareDiscovery
{
    return new class($hosts, $names) extends ShareDiscovery
    {
        public function __construct(private array $hosts, private array $names)
        {
            parent::__construct(silentMdns());
        }

        public function find(array $hints = []): array
        {
            return $this->hosts;
        }

        public function subnets(array $hints = []): array
        {
            return ['192.168.1'];
        }

        public function resolve(string $name): ?string
        {
            return $this->names[$name] ?? null;
        }
    };
}

it('searches on the queue, and shows what answered when the signal comes', function () {
    Queue::fake();

    $page = Livewire::test('settings.destinations')->call('search');
    $token = $page->get('searchToken');

    Queue::assertPushed(DiscoverShares::class, fn (DiscoverShares $job) => $job->token === $token);

    app()->instance(ShareDiscovery::class, discoveryFinding([new FoundHost('batocera', '192.168.1.20', 'mdns')]));
    app()->call([new DiscoverShares($token), 'handle']);

    $page->call('checkSearch')
        ->assertSet('searchToken', null)
        ->assertSee('batocera')
        ->assertSee('192.168.1.20');
});

it('says why it could not search the whole network, when it had none to scan', function () {
    Cache::put(DiscoverShares::cacheKey('t'), ['hosts' => [], 'subnets' => []]);

    Livewire::test('settings.destinations')
        ->set('searchToken', 't')
        ->set('searchSince', now()->timestamp)
        ->call('checkSearch')
        ->assertSee('Set HOST_IP in .env');
});

it('scans the network HOST_IP is on, or else the one the page was opened on — never Docker\'s own or a public one', function () {
    $discovery = new class(silentMdns()) extends ShareDiscovery
    {
        protected function ownAddresses(): array
        {
            return ['172.18.0.3'];
        }
    };

    config()->set('transfer.discovery.host_ip', '10.20.10.237');
    expect($discovery->subnets(['192.168.1.5']))->toBe(['10.20.10']);

    config()->set('transfer.discovery.host_ip', null);
    expect($discovery->subnets(['localhost', '172.18.0.1', '192.168.1.5']))->toBe(['192.168.1'])
        ->and($discovery->subnets(['8.8.8.8', '127.0.0.1']))->toBe([]);

    config()->set('transfer.discovery.subnets', ['10.1.2.0/24', 'nonsense']);
    expect($discovery->subnets(['192.168.1.5']))->toBe(['10.1.2']);
});

it('names what the scan found by asking each machine for its NetBIOS name', function () {
    Process::fake(['*nmblookup*' => Process::result("Looking up status of 10.20.10.252\n\tBATOCERA        <00> -         B <ACTIVE>\n\tWORKGROUP       <00> - <GROUP> B <ACTIVE>\n")]);
    config()->set('transfer.discovery.names', []);
    config()->set('transfer.discovery.host_ip', '10.20.10.237');

    $discovery = new class(silentMdns()) extends ShareDiscovery
    {
        protected function scan(string $prefix): array
        {
            return [$prefix.'.237', $prefix.'.252'];
        }
    };

    // The machine retroBite runs on is left out: that share is the library's own.
    expect(array_map(fn (FoundHost $host) => $host->toArray(), $discovery->find()))->toBe([
        ['name' => 'BATOCERA', 'address' => '10.20.10.252', 'via' => 'scan'],
    ]);
});

it('takes a found machine by address, and asks it for its shares', function () {
    Queue::fake();

    Livewire::test('settings.destinations')
        ->set('found', [['name' => 'BATOCERA', 'address' => '192.168.1.20', 'via' => 'scan']])
        ->call('pick', 0)
        ->assertSet('host', '192.168.1.20')
        ->assertSet('name', 'Batocera');

    Queue::assertPushed(ListShares::class, fn (ListShares $job) => $job->host === '192.168.1.20');
});

it('lists a machine\'s shares, and picks the only one', function () {
    $root = sys_get_temp_dir().'/retrobite-dest-'.Str::random(8);
    File::ensureDirectoryExists($root.'/192.168.1.20/share');
    app()->instance(ShareClient::class, new FolderShareClient($root));
    app()->instance(ShareDiscovery::class, discoveryFinding([], ['batocera' => '192.168.1.20']));
    Queue::fake();

    $page = Livewire::test('settings.destinations')->set('host', 'batocera')->call('listShares');
    app()->call([new ListShares($page->get('listToken'), 'batocera'), 'handle']);

    $page->call('checkListing')->assertSet('share', 'share');

    File::deleteDirectory($root);
});

it('says a machine that did not answer did not answer', function () {
    app()->instance(ShareDiscovery::class, discoveryFinding([]));
    Queue::fake();

    $page = Livewire::test('settings.destinations')->set('host', 'nowhere')->call('listShares');
    app()->call([new ListShares($page->get('listToken'), 'nowhere'), 'handle']);

    $page->call('checkListing')->assertSee('Nothing answered at nowhere.');
});

it('keeps a password off the queue in plain text', function () {
    expect(new ListShares('t', 'h', 'u', 'p'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('saves a destination with its password encrypted, and removes it', function () {
    Livewire::test('settings.destinations')
        ->set('name', 'Living room')
        ->set('host', 'batocera')
        ->set('share', 'share')
        ->set('folder', '\\batocera\\')
        ->set('username', 'root')
        ->set('password', 'linux')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('\\\\batocera\\share\\batocera');

    $destination = Destination::query()->sole();

    expect($destination->folder)->toBe('batocera')
        ->and($destination->password)->toBe('linux')
        ->and($destination->getRawOriginal('password'))->not->toContain('linux');

    Livewire::test('settings.destinations')->call('remove', $destination->id);

    expect(Destination::query()->count())->toBe(0);
});

it('edits a destination, keeping its password unless a new one is typed', function () {
    $destination = Destination::query()->create(['name' => 'RP4', 'host' => '10.20.10.252', 'share' => 'share', 'folder' => 'roms', 'username' => 'root', 'password' => 'linux']);

    Livewire::test('settings.destinations')
        ->call('edit', $destination->id)
        ->assertSet('folder', 'roms')
        ->assertSet('password', '')
        ->assertSee('Edit RP4')
        ->set('folder', '')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', null);

    expect($destination->fresh())
        ->folder->toBe('')
        ->password->toBe('linux')
        ->and(Destination::query()->count())->toBe(1);

    Livewire::test('settings.destinations')->call('edit', $destination->id)->set('password', 'batocera')->call('save');

    expect($destination->fresh()->password)->toBe('batocera');
});

it('refuses the roms folder itself, which the target adds to every path', function () {
    foreach (['roms', 'batocera/roms/', 'ROMS'] as $folder) {
        Livewire::test('settings.destinations')
            ->set('name', 'x')->set('host', 'batocera')->set('share', 'share')->set('folder', $folder)
            ->call('save')
            ->assertHasErrors('folder')
            ->assertSee('Send to adds roms/ itself');
    }

    Livewire::test('settings.destinations')
        ->set('name', 'x')->set('host', 'batocera')->set('share', 'share')->set('folder', 'userdata')
        ->call('save')
        ->assertHasNoErrors();
});

it('refuses a folder that climbs out of the share', function () {
    Livewire::test('settings.destinations')
        ->set('name', 'x')->set('host', 'batocera')->set('share', 'share')->set('folder', 'roms/../..')
        ->call('save')
        ->assertHasErrors('folder');
});

it('resolves an address as it is, and a name by NetBIOS when DNS does not know it', function () {
    Process::fake(['*nmblookup*' => Process::result("querying BATOCERA on 192.168.1.255\n192.168.1.20 BATOCERA<00>\n")]);

    $discovery = new class(silentMdns()) extends ShareDiscovery
    {
        protected function dns(string $name): ?string
        {
            return null;
        }
    };

    expect($discovery->resolve('10.0.0.5'))->toBe('10.0.0.5')
        ->and($discovery->resolve('batocera'))->toBe('192.168.1.20')
        ->and($discovery->resolve('bad name; rm -rf'))->toBeNull();
});

it('is a tab in settings', function () {
    $this->get(route('destinations.edit'))->assertOk()->assertSee('Search the network');
});
