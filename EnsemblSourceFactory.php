<?php

namespace Omnilab\Ensembl;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Ensembl: genes, transcripts, proteins and exons by their Ensembl id, genes
 * by symbol in a species - their location, biotype, assembly, species and
 * cross-references - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom Ensembl writes to before it blocks a caller
 *     species: 'homo_sapiens'          # the species a symbol is looked up in when it names none ("mus_musculus/Hbb-bs" does)
 *     xrefs: true                      # read a gene's or a protein's cross-references (NCBI Gene, UniProt): one or two calls more
 *     throttle: 0.07                   # seconds between two calls: Ensembl takes 15 a second at most (55,000 an hour)
 *     base_uri: 'https://rest.ensembl.org/'
 */
final class EnsemblSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'ensembl',
            'omnilab.required_options' => [],
            'species' => EnsemblSource::SPECIES,
            'xrefs' => true,
            'throttle' => EnsemblSource::THROTTLE,
            'base_uri' => EnsemblSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new EnsemblSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('species'),
            (bool) $c->get('xrefs'),
            (string) $c->get('base_uri', EnsemblSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
