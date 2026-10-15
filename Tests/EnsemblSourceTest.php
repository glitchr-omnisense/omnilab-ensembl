<?php

namespace Omnilab\Ensembl\Tests;

use Omnilab\Ensembl\EnsemblSource;
use Omnilab\Ensembl\EnsemblSourceFactory;
use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from rest.ensembl.org (release 116) on 2026-10-15: the
 * human HBB gene (ENSG00000244734) - its record, its cross-references, its
 * Swiss-Prot proteins at every level -, its canonical transcript expanded
 * (ENST00000335295), its protein (ENSP00000333994) and its UniProt entries,
 * the lookup of the symbol HBB, the genomes of human and mouse, the genes
 * the symbol Hbb-bs names in the mouse and their batch lookup, and
 * Ensembl's 400 for an unknown id and an unknown symbol. That evening, the
 * fly's gene a (FBgn0000008, FlyBase's id) asked with its species: its
 * record, its cross-references, its Swiss-Prot proteins (none at the
 * gene's levels), the fly's genome.
 */
final class EnsemblSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    /** @var list<array<string, mixed>> the headers of each call */
    private array $headers = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($response): MockResponse {
            $path = ltrim((string) parse_url($url, \PHP_URL_PATH), '/');
            $query = rawurldecode((string) parse_url($url, \PHP_URL_QUERY));
            $this->calls[] = ('POST' === $method ? 'POST ' : '').$path.('' === $query ? '' : '?'.$query);
            $this->headers[] = $o['normalized_headers'];
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                'POST' === $method && 'lookup/id' === $path => 'batch-Hbb-bs.json',
                str_starts_with($path, 'lookup/id/') => 'lookup-'.basename($path).'.json',
                str_starts_with($path, 'lookup/symbol/') => 'symbol-'.str_replace('/', '-', substr($path, 14)).'.json',
                str_starts_with($path, 'xrefs/symbol/') => 'xsymbol-'.str_replace('/', '-', substr($path, 13)).'.json',
                str_starts_with($path, 'xrefs/id/') && str_contains($query, 'Uniprot/SWISSPROT') => 'swissprot-'.basename($path).'.json',
                str_starts_with($path, 'xrefs/id/') => 'xrefs-'.basename($path).'.json',
                str_starts_with($path, 'info/genomes/') => 'genome-'.basename($path).'.json',
                default => null,
            };
            if (null === $fixture || !is_file(__DIR__.'/Fixtures/'.$fixture)) {
                return new MockResponse('{"error":"no fixture for '.$path.'"}', ['http_code' => 500]);
            }
            $body = (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture);

            return new MockResponse($body, ['http_code' => str_contains($body, '"error"') ? 400 : 200]);
        });

        // No wait between the recorded answers; the throttle's default is checked apart.
        return (new EnsemblSourceFactory($http))->create($options + ['throttle' => 0.0]);
    }

    public function testAHumanGeneInFourCalls(): void
    {
        $hbb = $this->source()->entity('ENSG00000244734');

        self::assertSame('ensembl:ENSG00000244734', $hbb->id->key());
        self::assertSame(['HBB', Domain::BIOLOGY, 'gene'], [$hbb->label, $hbb->domain, $hbb->kind]);
        self::assertSame('hemoglobin subunit beta', $hbb->description);
        self::assertSame(['HBB', 'hemoglobin subunit beta', 'HGNC Symbol', 'protein_coding'], [$hbb->value('gene'), $hbb->value('gene_name'), $hbb->value('name_source'), $hbb->value('biotype')]);
        self::assertSame(['Homo sapiens', 'Human', 'GRCh38', '11'], [$hbb->value('organism'), $hbb->value('organism_common_name'), $hbb->value('assembly'), $hbb->value('chromosome')]);
        self::assertSame('11:5225464-5229395 (minus strand), GRCh38', $hbb->value('genomic_location'));
        self::assertSame('3932 bp', (string) $hbb->property('sequence_length'));
        self::assertSame(['ENST00000335295.4', 6, 'ensembl_havana', 'HGNC:4827'], [$hbb->value('canonical_transcript'), $hbb->value('version'), $hbb->value('annotation_source'), $hbb->value('nomenclature_id')]);
        self::assertSame('ensembl', $hbb->property('biotype')->source);
        self::assertSame('https://www.ensembl.org/id/ENSG00000244734', $hbb->url);
        self::assertSame(['lookup/id/ENSG00000244734', 'info/genomes/homo_sapiens', 'xrefs/id/ENSG00000244734', 'xrefs/id/ENSG00000244734?all_levels=1&external_db=Uniprot/SWISSPROT'], $this->calls);
    }

    public function testItsNcbiGeneAndSwissProtProteinAreCrossReferencesItsSpeciesAndTranscriptRelated(): void
    {
        $hbb = $this->source()->entity(Identifier::of(Scheme::ENSEMBL, 'ENSG00000244734'));

        self::assertSame(['ensembl:ENSG00000244734', 'ncbi.gene:3043', 'uniprot:P68871'], $hbb->identifiers()->keys(), 'the reviewed entry only: the gene\'s own list mixes in four unreviewed ones');
        self::assertSame(['ncbi.taxon:9606'], $hbb->related('organism')->keys());
        self::assertSame(['ensembl:ENST00000335295'], $hbb->related('transcript')->keys(), 'its canonical transcript, its version dropped');
        self::assertSame(['hemoglobin subunit beta', 'BETA-GLOBIN', 'CD113T-C'], $hbb->synonyms, 'its name, then HGNC\'s synonyms');

        $this->calls = [];
        $bare = $this->source(null, ['xrefs' => false])->entity('ENSG00000244734');
        self::assertSame(['ensembl:ENSG00000244734'], $bare->identifiers()->keys());
        self::assertSame(['lookup/id/ENSG00000244734', 'info/genomes/homo_sapiens'], $this->calls, 'no cross-references asked: two calls');
    }

    public function testTheSameGeneByItsVersionItsPageOrItsSymbol(): void
    {
        foreach (['ENSG00000244734.6', 'ensembl:ENSG00000244734', 'https://www.ensembl.org/Homo_sapiens/Gene/Summary?g=ENSG00000244734', 'HBB', 'homo_sapiens/HBB'] as $id) {
            self::assertSame('ensembl:ENSG00000244734', $this->source(null, ['xrefs' => false])->entity($id)?->id->key(), $id);
        }
        $this->calls = [];
        $this->source(null, ['xrefs' => false])->entity('HBB');
        self::assertSame('lookup/symbol/homo_sapiens/HBB', $this->calls[0], 'a symbol in the configured species');
    }

    public function testATranscriptExpandedInOneCall(): void
    {
        $transcript = $this->source()->entity('ENST00000335295');

        self::assertSame(['HBB-201', 'transcript', 'protein_coding'], [$transcript->label, $transcript->kind, $transcript->value('biotype')]);
        self::assertSame([true, true, 3], [$transcript->value('canonical'), $transcript->value('gencode_primary'), $transcript->value('exon_count')]);
        self::assertSame(['628 bp', '147 aa'], [(string) $transcript->property('sequence_length'), (string) $transcript->property('protein_length')], 'its spliced length');
        self::assertSame('11:5225464-5227071 (minus strand), GRCh38', $transcript->value('genomic_location'));
        self::assertSame([['ensembl:ENSG00000244734'], ['ensembl:ENSP00000333994'], ['ncbi.taxon:9606']], [$transcript->related('gene')->keys(), $transcript->related('protein')->keys(), $transcript->related('organism')->keys()]);
        self::assertSame([], $transcript->identifiers->keys());
        self::assertSame(['lookup/id/ENST00000335295?expand=1', 'info/genomes/homo_sapiens'], $this->calls);
    }

    public function testAProteinItsUniProtEntriesTheReviewedFirst(): void
    {
        $protein = $this->source()->entity('ENSP00000333994');

        self::assertSame(['ENSP00000333994', 'protein', 'HBB-201'], [$protein->label, $protein->kind, $protein->value('transcript_name')]);
        self::assertSame('147 aa', (string) $protein->property('sequence_length'));
        self::assertSame('11:5225598-5227021 (minus strand), GRCh38', $protein->value('genomic_location'), 'the coding part, on its transcript\'s chromosome');
        self::assertSame(['ensembl:ENSP00000333994', 'uniprot:P68871', 'uniprot:D9YZU5'], $protein->identifiers()->keys(), 'Swiss-Prot before TrEMBL, whatever Ensembl\'s order');
        self::assertSame([['ensembl:ENST00000335295'], ['ensembl:ENSG00000244734']], [$protein->related('transcript')->keys(), $protein->related('gene')->keys()]);
        self::assertSame(['lookup/id/ENSP00000333994', 'lookup/id/ENST00000335295', 'info/genomes/homo_sapiens', 'xrefs/id/ENSP00000333994?external_db=Uniprot%'], $this->calls);
    }

    public function testEachSpeciesIsReadOnce(): void
    {
        $source = $this->source(null, ['xrefs' => false]);
        $source->entity('ENSG00000244734');
        $source->entity('ENST00000335295');

        self::assertSame(1, \count(array_filter($this->calls, static fn (string $c) => str_starts_with($c, 'info/genomes/'))));
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('ENSG00000000000'), 'Ensembl answers 400 "not found"');
        self::assertNull($this->source()->entity('NOTAGENE'), '400 "No valid lookup found for symbol"');
        self::assertNull($this->source()->entity(''));
        self::assertNull($this->source(new MockResponse('{"id":"LRG_1232","object_type":"Gene","display_name":"HBB","species":"homo_sapiens"}'))->entity('homo_sapiens/LRG-HBB'), 'a locus reference is no gene of Ensembl\'s');
    }

    public function testAnotherErrorIsNotNull(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage("Can not find internal name for species 'martian'");
        $this->source(new MockResponse('{"error":"Can not find internal name for species \'martian\'"}', ['http_code' => 400]))->entity('martian/HBB');
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::UNIPROT, 'P68871'), Identifier::of(Scheme::NCBI_GENE, '3043'), 'Q424422', 'ncbigene:3043', 'pdb:4HHB'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheGenesASymbolNamesInASpecies(): void
    {
        $page = $this->source()->search(new Query('mus_musculus/Hbb-bs'));

        self::assertSame(['ensembl:ENSMUSG00000052305', 'ensembl:ENSMUSG00000073940'], array_map(static fn ($e) => $e->id->key(), $page->items), 'in the order Ensembl matched them, not its batch answer\'s');
        self::assertSame(['Hbb-bs', 'Hbb-bt'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame([2, null], [$page->total, $page->next]);
        self::assertSame(['Mus musculus', 'Mouse', 'GRCm39', '7', 'MGI Symbol'], [$page->first()->value('organism'), $page->first()->value('organism_common_name'), $page->first()->value('assembly'), $page->first()->value('chromosome'), $page->first()->value('name_source')]);
        self::assertSame(['ncbi.taxon:10090'], $page->first()->related('organism')->keys());
        self::assertSame(['xrefs/symbol/mus_musculus/Hbb-bs?object_type=gene', 'POST lookup/id', 'info/genomes/mus_musculus'], $this->calls, 'one batch lookup for the page, no cross-references');
    }

    public function testASearchPagesByOffsetAndLeavesOutWhatIsNoEnsemblGene(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('HBB', limit: 1));

        self::assertSame([1, null], [$page->total, $page->next], 'LRG_1232, a locus reference, is no Ensembl gene');
        self::assertSame([], $source->search(new Query('NOTAGENE'))->items);
        self::assertSame([], $source->search(new Query('HBB', Domain::CHEMISTRY))->items, 'nothing of another domain');

        $first = $source->search(new Query('mus_musculus/Hbb-bs', limit: 1));
        self::assertSame(['ensembl:ENSMUSG00000052305', '1', 2], [$first->first()?->id->key(), $first->next, $first->total]);
    }

    public function testEveryCallSaysJsonAndNamesTheCaller(): void
    {
        $this->source(null, ['mailto' => 'support@glitchr.io', 'xrefs' => false])->entity('ENSG00000244734');

        self::assertSame('Content-Type: application/json', $this->headers[0]['content-type'][0], 'Ensembl tells its formats by the Content-Type, a GET\'s too');
        self::assertStringContainsString('mailto:support@glitchr.io', $this->headers[0]['user-agent'][0]);
    }

    public function testCapabilitiesAndRateLimit(): void
    {
        $source = $this->source();
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $source->capabilities());
        self::assertSame('ensembl', $source->getName());
        self::assertSame(EnsemblSource::THROTTLE, (new \ReflectionProperty($source, 'throttle'))->getValue((new EnsemblSourceFactory(new MockHttpClient()))->create()), '15 calls a second at most');

        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('{"error":"You have exceeded the limit of 15 requests per second"}', ['http_code' => 429, 'response_headers' => ['Retry-After' => '1']]))->entity('ENSG00000244734');
    }

    public function testAFlyGeneByItsCommunitysId(): void
    {
        $gene = $this->source()->entity('FBgn0000008');

        self::assertSame(['ensembl.genomes:FBgn0000008', 'a', 'gene'], [$gene?->id->key(), $gene?->label, $gene?->kind]);
        self::assertSame('lookup/id/FBgn0000008?species=drosophila_melanogaster', $this->calls[0], 'the genome its shape tells: a lookup without it took 7 to 23 s');
        self::assertContains('xrefs/id/FBgn0000008?species=drosophila_melanogaster', $this->calls);
        self::assertSame(['ensembl.genomes:FBgn0000008', 'ncbi.gene:43852'], $gene->identifiers()->keys(), 'its NCBI Gene record, for the Collector');
        self::assertSame('ncbi.taxon:7227', $gene->related('organism')->keys()[0]);
        self::assertSame('ensembl.genomes:FBtr0071764', $gene->related('transcript')->keys()[0] ?? null, 'its canonical transcript, FlyBase\'s id too');
        self::assertSame('Drosophila melanogaster', $gene->value('organism'));
        self::assertSame('https://www.ensembl.org/id/FBgn0000008', $gene->url);
        self::assertSame($gene->label, $this->source()->entity(Identifier::of(Scheme::ENSEMBL_GENOMES, 'fbgn0000008'))?->label, 'its scheme, its case aside');
    }
}
