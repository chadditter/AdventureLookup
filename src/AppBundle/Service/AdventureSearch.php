<?php

namespace AppBundle\Service;

use AppBundle\Entity\AdventureDocument;
use AppBundle\Exception\FieldDoesNotExistException;
use AppBundle\Field\Field;
use AppBundle\Field\FieldProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AdventureSearch
{
    const ADVENTURES_PER_PAGE = 20;

    /**
     * @var \Elasticsearch\Client
     */
    private $client;

    /**
     * @var FieldProvider
     */
    private $fieldProvider;

    /**
     * @var string
     */
    private $indexName;

    /**
     * @var TimeProvider
     */
    private $timeProvider;

    public function __construct(FieldProvider $fieldProvider, ElasticSearch $elasticSearch, TimeProvider $timeProvider)
    {
        $this->fieldProvider = $fieldProvider;
        $this->client = $elasticSearch->getClient();
        $this->indexName = $elasticSearch->getIndexName();
        $this->timeProvider = $timeProvider;
    }

    /**
     * @return array
     */
    public function requestToSearchParams(Request $request)
    {
        $q = $request->get('q', '');
        $sortBy = $request->get('sortBy', '');
        // Change the seed once per week to give the frontpage a fresh look once in a while.
        // We don't want to change the seed every single day/minute/second, because it
        // might be confusing for users that go back and forth between the adventure
        // search and an adventure details page if the order suddenly is completely different.
        $seed = (string) $request->get('seed', $this->timeProvider->yearAndWeek());
        $page = (int) $request->get('page', 1);

        $filters = [];
        foreach ($this->fieldProvider->getFieldsAvailableAsFilter() as $field) {
            $value = $request->get($field->getName(), '');
            switch ($field->getType()) {
                case 'integer':
                    list($valueMin, $valueMax, $includeUnknown) = $this->parseIntFilterValue($value);
                    $filters[$field->getName()] = [
                        'v' => [
                            'min' => $valueMin,
                            'max' => $valueMax,
                        ],
                        'includeUnknown' => $includeUnknown,
                    ];
                    break;
                case 'string':
                    list($values, $includeUnknown) = $this->parseStringFilterValue($value);
                    $filters[$field->getName()] = [
                        'v' => $values,
                        'includeUnknown' => $includeUnknown,
                    ];
                    break;
                case 'boolean':
                    list($value, $includeUnknown) = $this->parseBooleanFilterValue($value);
                    $filters[$field->getName()] = [
                        'v' => $value,
                        'includeUnknown' => $includeUnknown,
                    ];
                    break;
                case 'text':
                case 'url':
                    // Not supported as filters
                    break;
                default:
                    throw new \LogicException('Cannot handle field of type '.$field->getType());
            }
        }

        return [$q, $filters, $page, $sortBy, $seed];
    }

    private function parseStringFilterValue(string $value): array
    {
        $includeUnknown = false;

        preg_match('#^(.*?)([^~]+)~$#', $value, $matches);
        if (!empty($matches)) {
            // If the value ends with "~" preceded by something else than a "~", then the
            // last argument is special and includes additional options.
            $value = $matches[1];
            $additionalOptions = $matches[2];

            if ('unknown' === $additionalOptions) {
                $includeUnknown = true;
            }
        }

        // Split the string on all "~" that are neither preceded nor followed by another "~".
        $values = preg_split('#(?<!~)~(?!~)#', $value, -1, PREG_SPLIT_NO_EMPTY);

        $values = array_map(function (string $value): string {
            // Undo escaping of '~' character.
            return str_replace('~~', '~', $value);
        }, $values);

        return [$values, $includeUnknown];
    }

    public function parseIntFilterValue(string $value): array
    {
        $valueMin = '';
        $valueMax = '';
        $includeUnknown = false;
        $parts = explode('~', $value);
        foreach ($parts as $part) {
            if ('unknown' === $part) {
                $includeUnknown = true;
            } elseif (0 === mb_strpos($part, '≥')) {
                $n = mb_substr($part, 1);
                if ($this->isValidIntFilterValue($n)) {
                    $valueMin = $n;
                }
            } elseif (0 === mb_strpos($part, '≤')) {
                $n = mb_substr($part, 1);
                if ($this->isValidIntFilterValue($n)) {
                    $valueMax = $n;
                }
            }
        }

        if ('' === $valueMin && '' === $valueMax) {
            $includeUnknown = false;
        }

        return [$valueMin, $valueMax, $includeUnknown];
    }

    private function isValidIntFilterValue(string $value): bool
    {
        if ('' === $value) {
            return true;
        }

        // ElasticSearch integer fields are signed 32 bit values.
        // https://www.elastic.co/guide/en/elasticsearch/reference/5.5/number.html
        // We deliberately set 2**30 as the upper bound, to make sure
        // this code works correctly, even on 32 bit machines, without
        // having to think about what happens when you go 1 over
        // PHP_INT_MAX.
        //
        // We set 0 as the lower bound, since negative values make no
        // sense for any of the integer fields we use so far.
        //
        // We also have to make sure that $value does not contain leading or trailing
        // whitespace, which filter_var accepts, but ElasticSearch doesn't.
        return trim($value) === $value && false !== filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0,
                'max_range' => 2 ** 30,
            ],
        ]);
    }

    private function parseBooleanFilterValue(string $values): array
    {
        $values = explode('~', $values);
        $includeUnknown = false;
        $value = '';
        foreach ($values as $each) {
            if ('unknown' === $each) {
                $includeUnknown = true;
            } elseif ('1' === $each || '0' === $each) {
                $value = $each;
            }
        }

        if ('' === $value) {
            $includeUnknown = false;
        }

        return [$value, $includeUnknown];
    }

    /**
     * @param string $seed random seed used when adventures have to be sorted randomly
     *
     * @return array
     */
    public function search(string $q, array $filters, int $page, string $sortBy, string $seed, int $perPage = self::ADVENTURES_PER_PAGE)
    {
        if ($page < 1 || $page * self::ADVENTURES_PER_PAGE > 5000) {
            throw new BadRequestHttpException();
        }

        $matches = [];

        // First generate ES search query from free-text searchbar at the top.
        // This will only search string and text fields.
        $matches = $this->qMatches($q, $matches);
        $hasQuery = !empty($matches);

        // Now apply filters from the sidebar.
        $matches = $this->filterMatches($filters, $matches);

        // If we neither have a filter, nor any kind of free-text search, return all adventures.
        if (empty($matches)) {
            $matches = ['match_all' => new \stdClass()];
        }

        $query = [
            // All matches must evaluate to true for a result to be returned.
            'bool' => [
                'must' => $matches,
            ],
        ];

        switch ($sortBy) {
            case 'title':
                $sort = 'title.keyword';
            break;
            case 'numPages-asc':
                // Sort by the number of pages, but use the score as a tie breaker
                // if the number of pages is the same for two adventures.
                $sort = [
                    ['numPages' => 'asc'],
                    '_score',
                ];
            break;
            case 'numPages-desc':
                $sort = [
                    ['numPages' => 'desc'],
                    '_score',
                ];
            break;
            case 'createdAt-asc':
                // No need to use the score as a tie breaker, since two adventures
                // will almost never be created at the exact same second.
                $sort = ['createdAt' => 'asc'];
            break;
            case 'createdAt-desc':
                $sort = ['createdAt' => 'desc'];
            break;
            case 'reviews':
                // We use the Wilson Score instead of the average of positive and negative reviews
                // https://www.elastic.co/de/blog/better-than-average-sort-by-best-rating-with-elasticsearch
                // We use the score as a tie breaker just like with all the other sortings.
                $sort = [
                    [
                        '_script' => [
                            'order' => 'desc',
                            'type' => 'number',
                            'script' => [
                                'inline' => "
                                    long p = doc['positiveReviews'].value;
                                    long n = doc['negativeReviews'].value;
                                    return p + n > 0 ? ((p + 1.9208) / (p + n) - 1.96 * Math.sqrt((p * n) / (p + n) + 0.9604) / (p + n)) / (1 + 3.8416 / (p + n)) : 0;
                                ",
                            ],
                        ],
                    ],
                    '_score',
                ];
            break;
            // Sorting in a random order cannot be done using the 'sort' parameter, but requires adjusting the query
            // to use the random_score function for scoring.
            default:
                $sort = ['_score'];
            break;
        }

        if ('random' === $sortBy || !$hasQuery) {
            // Calculate a random score per adventure if
            // - sortBy is 'random' or
            // - the query is empty (-> all adventures would have the same score).
            //
            // Note that usage of the calculated score depends on whether `_score` is part of $sort.
            // https://www.elastic.co/guide/en/elasticsearch/reference/7.7/query-dsl-function-score-query.html#function-random
            $query = [
                'function_score' => [
                    'query' => $query,
                    'random_score' => [
                        // Calculate the random score based on the $seed and an adventure's id.
                        // Given that the $id of an adventure never changes, the random score
                        // is only dependent on the $seed.
                        'seed' => $seed,
                        'field' => 'id',
                    ],
                ],
            ];
        }

        $result = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'query' => $query,
                'from' => $perPage * ($page - 1),
                'size' => $perPage,
                // Also return aggregations for all fields, i.e. min/max for integer fields
                // or the most common strings for string fields.
                'aggs' => $this->fieldAggregations(),
                'sort' => $sort,
            ],
        ]);

        $adventureDocuments = array_map(fn ($hit) => AdventureDocument::fromHit($hit), $result['hits']['hits']);
        $totalResults = $result['hits']['total']['value'];
        $hasMoreResults = $totalResults > $page * self::ADVENTURES_PER_PAGE;

        $stats = $this->formatAggregations($result['aggregations']);

        return [$adventureDocuments, $totalResults, $hasMoreResults, $stats];
    }

    public function similarTitles(string $title, int $ignoreId): array
    {
        if ('' === $title) {
            return [];
        }

        $query = [
            'match' => [
                'title' => [
                    'query' => $title,
                    'operator' => 'and',
                    'fuzziness' => 'AUTO',
                ],
            ],
        ];
        if ($ignoreId >= 0) {
            $query = [
                'bool' => [
                    'must' => [
                        $query,
                    ],
                    'must_not' => [
                        'term' => [
                            'id' => [
                                'value' => $ignoreId,
                            ],
                        ],
                    ],
                ],
            ];
        }

        $result = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'query' => $query,
                '_source' => [
                    'id',
                    'title',
                    'slug',
                ],
                'size' => 10,
            ],
        ]);

        return array_map(function ($hit) {
            return $hit['_source'];
        }, $result['hits']['hits']);
    }

    public function similarAdventures(int $id, string $fieldName): array
    {
        $fields = [];
        if ('title/description' === $fieldName) {
            $fields[] = 'title.analyzed';
            $fields[] = 'description.analyzed';
        }
        if ('items' === $fieldName) {
            $fields[] = 'items.keyword';
        }
        if ('bossMonsters' === $fieldName) {
            $fields[] = 'bossMonsters.keyword';
        }
        if ('commonMonsters' === $fieldName) {
            $fields[] = 'commonMonsters.keyword';
        }

        if (empty($fields)) {
            return [[], []];
        }

        // $id is the adventure id from the MySQL table.
        // We first need to convert it into the internal id used by ElasticSearch.
        $result = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'query' => [
                    'term' => [
                        'id' => $id,
                    ],
                ],
                '_source' => [],
                'size' => 1,
            ],
        ]);
        if (1 !== count($result['hits']['hits'])) {
            return [[], []];
        }
        $elasticSearchId = $result['hits']['hits'][0]['_id'];

        // Now we need to gather statistics on all terms used in the selected adventure.
        $result = $this->client->termvectors([
            'index' => $this->indexName,
            'id' => $elasticSearchId,
            'fields' => $fields,
            'positions' => false,
            'offsets' => false,
            'payloads' => false,
            'term_statistics' => true,
            'realtime' => false,
        ]);

        // Given these statistics, we now calculated TF-IDF per term.
        $terms = [];
        foreach ($fields as $field) {
            if (!isset($result['term_vectors'][$field])) {
                // Field is empty
                continue;
            }
            $fieldData = $result['term_vectors'][$field];
            $docCount = $fieldData['field_statistics']['doc_count'];

            foreach ($fieldData['terms'] as $term => $termStatistics) {
                if (mb_strlen($term) < 3) {
                    continue;
                }
                // TF-IDF is calculated based on the formula from here:
                // https://www.elastic.co/guide/en/elasticsearch/reference/7.6/index-modules-similarity.html#scripted_similarity
                // TF-IDF is higher the more unique a term is across all documents.
                $tf = sqrt($termStatistics['term_freq']);
                $idf = log(($docCount + 1.0) / ($termStatistics['doc_freq'] + 1.0)) + 1.0;
                $terms[] = [
                    'field' => $field,
                    'term' => $term,
                    'tf-idf' => $tf * $idf,
                ];
            }
        }

        // Take the top 20 terms with the highest TF-IDF
        usort($terms, fn ($a, $b) => $b['tf-idf'] <=> $a['tf-idf']);
        $terms = array_slice($terms, 0, 20);

        if (empty($terms)) {
            return [[], []];
        }

        // Search for the top 6 adventures that contain at least 25% of the given terms.
        $result = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [
                                'bool' => [
                                    'should' => array_map(function ($term) {
                                        return [
                                            'match' => [
                                                $term['field'] => [
                                                    'query' => $term['term'],
                                                    // Boost adventures that contain terms with a high TF-IDF.
                                                    'boost' => $term['tf-idf'],
                                                ],
                                            ],
                                        ];
                                    }, $terms),
                                    'minimum_should_match' => '25%',
                                ],
                            ],
                            // Exclude the adventure itself
                            [
                                'bool' => [
                                    'must_not' => [
                                        'term' => [
                                            'id' => $id,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'size' => 6,
            ],
        ]);

        return [
            array_map(fn ($hit) => AdventureDocument::fromHit($hit), $result['hits']['hits']),
            $terms,
        ];
    }

    /**
     * Given a field and an input query, return a list of values
     * which could possibly be what the user wants to insert.
     * If the query is empty, return the most common values.
     */
    public function autocompleteFieldContent(Field $field, string $q): array
    {
        $size = 20;
        if ('' === $q) {
            return current($this->aggregateMostCommonValues([$field], $size));
        }

        $fieldName = $field->getName();
        $response = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'query' => [
                    'match_phrase_prefix' => [
                        $fieldName => $q,
                    ],
                ],
                'size' => $size,
                '_source' => false,
                'highlight' => [
                    'pre_tags' => [''],
                    'post_tags' => [''],
                    'fields' => [
                        $fieldName => new \stdClass(),
                    ],
                ],
            ],
        ]);

        $results = [];
        foreach ($response['hits']['hits'] as $hit) {
            if (!isset($hit['highlight'])) {
                continue;
            }
            $highlights = array_unique($hit['highlight'][$fieldName]);
            foreach ($highlights as $highlight) {
                if (!in_array($highlight, $results)) {
                    $results[] = $highlight;
                }
            }
        }

        return $results;
    }

    /**
     * @param Field[] $fields
     */
    private function aggregateMostCommonValues(array $fields, int $size): array
    {
        $aggregations = [];
        foreach ($fields as $field) {
            $elasticField = $field->getFieldNameForAggregation();
            if (!$elasticField) {
                // This field cannot be aggregated.
                continue;
            }
            $aggregations[$elasticField] = [
                'terms' => [
                    'field' => $elasticField,
                    'size' => $size,
                ],
            ];
        }

        $response = $this->client->search([
            'index' => $this->indexName,
            'body' => [
                'size' => 0,
                'aggregations' => $aggregations,
            ],
            'request_cache' => true,
        ]);

        $results = [];
        foreach ($response['aggregations'] as $field => $aggregation) {
            $results[$field] = array_column($aggregation['buckets'], 'key');
        }

        return $results;
    }

    private function fieldAggregations(): array
    {
        $aggregations = [];
        $fields = $this->fieldProvider->getFieldsAvailableAsFilter();
        foreach ($fields as $field) {
            $fieldName = $field->getFieldNameForAggregation();
            $aggregations[$field->getName().'_missing'] = [
                'missing' => [
                    'field' => $fieldName,
                ],
            ];
            switch ($field->getType()) {
                case 'integer':
                    $aggregations[$field->getName().'_max'] = [
                        'max' => [
                            'field' => $fieldName,
                        ],
                    ];
                    $aggregations[$field->getName().'_min'] = [
                        'min' => [
                            'field' => $fieldName,
                        ],
                    ];
                break;

                // We use a Terms Aggregation
                // https://www.elastic.co/guide/en/elasticsearch/reference/5.5/search-aggregations-bucket-terms-aggregation.html
                case 'boolean':
                    $aggregations[$field->getName().'_terms'] = [
                        'terms' => [
                            'field' => $fieldName,
                        ],
                    ];
                    break;
                case 'string':
                    $aggregations[$field->getName().'_terms'] = [
                        'terms' => [
                            'field' => $fieldName,
                            // Return up to 1000 different values.
                            'size' => 1000,
                        ],
                    ];
                    break;
                default:
                    throw new \LogicException('Field '.$field->getName().' has unsupported type for aggregation: '.$field->getType());
            }
        }

        return $aggregations;
    }

    private function formatAggregations(array $aggregations): array
    {
        $stats = [];
        $fields = $this->fieldProvider->getFieldsAvailableAsFilter();
        foreach ($fields as $field) {
            switch ($field->getType()) {
                case 'integer':
                    $stats[$field->getName()] = [
                        'min' => (int) $aggregations[$field->getName().'_min']['value'],
                        'max' => (int) $aggregations[$field->getName().'_max']['value'],
                        'countUnknown' => $aggregations[$field->getName().'_missing']['doc_count'],
                    ];
                break;
                case 'boolean':
                    $countUnknown = $aggregations[$field->getName().'_missing']['doc_count'];
                    $countYes = 0;
                    $countNo = 0;
                    foreach ($aggregations[$field->getName().'_terms']['buckets'] as $bucket) {
                        if (0 === $bucket['key']) {
                            $countNo = $bucket['doc_count'];
                        } elseif (1 === $bucket['key']) {
                            $countYes = $bucket['doc_count'];
                        }
                    }
                    $stats[$field->getName()] = [
                        'countAll' => $countUnknown + $countNo + $countYes,
                        'countUnknown' => $countUnknown,
                        'countNo' => $countNo,
                        'countYes' => $countYes,
                    ];
                break;
                case 'string':
                    $stats[$field->getName()] = [
                        'countUnknown' => $aggregations[$field->getName().'_missing']['doc_count'],
                        'buckets' => $aggregations[$field->getName().'_terms']['buckets'],
                    ];
                break;
                default:
                    throw new \LogicException('Field '.$field->getName().' has unsupported type for aggregation: '.$field->getType());
            }
        }

        return $stats;
    }

    /**
     * Find adventures matching the free-text search query
     *
     * @param $matches
     */
    private function qMatches(string $q, $matches): array
    {
        // Get a list of freetext searchable fields and their individual boost values.
        $fields = $this->fieldProvider
            ->getFields()
            ->filter(function (Field $field) {
                return $field->isFreetextSearchable();
            })
            ->map(function (Field $field) {
                return $field->getName().'^'.$field->getSearchBoost();
            })
            ->getValues();

        // Implicitly, everything the user types in the search bar is ANDed together.
        // A search for 'galactic ghouls' should result in adventures that contain
        // both terms. If the user really wants to search for 'galactic OR ghouls',
        // the have to separate the terms by ' OR '.
        // The order of terms is irrelevant: Searching for 'galactic ghouls' leads
        // to the same results as searching for 'ghouls galactic'. We could look
        // into supporting quoting terms ('"galactic ghouls"') later, which would
        // NOT match adventures with 'ghouls galactic' or adventures with 'galactic'
        // and 'ghouls' in different fields.
        $clauses = explode(' OR ', $q);
        $orMatches = [];
        foreach ($clauses as $clause) {
            $terms = explode(' ', $clause);
            // All terms that are part of this clause have to be ANDed together.
            // Given the search query 'galactic ghouls', we don't care if both
            // 'galactic' and 'ghouls' appear in the same field (e.g., the title)
            // or appear on their own in different fields (e.g., 'galactic' in
            // the title and 'ghouls' in the description). That is why we can't
            // simply use a single 'multi_match' query with the operator set to
            // 'and' like this:
            // ['multi_match' => [
            //     'query' => 'galactic ghouls',
            //     'fields' => $fields,
            //     'type' => 'most_fields'
            //     'fuzziness' => 'AUTO',
            //     'prefix_length' => 2,
            //      'operator' => 'and'
            // ]]
            // This query would only return results where both terms appear in
            // the same field. We also can't use 'cross_fields' (instead of
            // 'most_fields'): While that allows terms to be distributed across
            // fields, it doesn't allow using fuzziness.
            //
            // That is why we create a multi_match query per term and AND them
            // together using a 'bool => 'must' query.
            $termMatches = [];
            foreach ($terms as $term) {
                if ('' == trim($term)) {
                    continue;
                }
                $termMatches[] = [
                    'multi_match' => [
                        'query' => $term,
                        'fields' => $fields,
                        // 'most_fields' combines the scores of all fields that
                        // contain the search term: If the term appears in title,
                        // description, and edition, the score of all of these
                        // occurrences is combined. This is better than using
                        // the default 'best_fields', which simply takes field
                        // with the highest score, discarding all lower scores.
                        'type' => 'most_fields',
                        // Fuzziness is helpful for typos and finding plural
                        // versions of the same word. We do not currently stem
                        // the description and title, which is why using some
                        // fuzziness is essential.
                        // Setting prefix_length to 2 causes fuzziness to not
                        // change the first 2 characters of search terms. As
                        // an example, take the search for 'ghouls':
                        // 'ghouls' only has an edit distanc of 2 to the term
                        // 'should'. We don't want searches for 'ghouls' to
                        // also match 'should', which is why we restrict the
                        // fuzziness to start after the second character.
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 2,
                    ],
                ];
            }
            if (!empty($termMatches)) {
                $orMatches[] = [
                    'bool' => [
                        'must' => $termMatches,
                    ],
                ];
            }
        }

        if (!empty($orMatches)) {
            // Combine the collected OR conditions.
            // At least one of them must match for an adventure to be returned.
            // The adventure will get a higher score if more than one matches.
            $matches[] = [
                'bool' => [
                    'should' => $orMatches,
                    'minimum_should_match' => 1,
                ],
            ];
        }

        return $matches;
    }

    private function filterMatches(array $filters, array $matches): array
    {
        // Iterate all user-provided filters
        foreach ($filters as $fieldName => $filter) {
            try {
                $field = $this->fieldProvider->getField($fieldName);
            } catch (FieldDoesNotExistException $e) {
                // The field does not exist. This normally never happens. Skip silently.
                continue;
            }

            switch ($field->getType()) {
                case 'integer':
                    $filterMatches = [];
                    if ('' !== $filter['v']['min']) {
                        $filterMatches[] = [
                            'range' => [
                                $field->getName() => [
                                    'gte' => $filter['v']['min'],
                                ],
                            ],
                        ];
                    }
                    if ('' !== $filter['v']['max']) {
                        $filterMatches[] = [
                            'range' => [
                                $field->getName() => [
                                    'lte' => $filter['v']['max'],
                                ],
                            ],
                        ];
                    }
                    if (!empty($filterMatches)) {
                        // Integer fields must use AND, because you want e.g. the page count to be between min AND max.
                        $match = [
                            'bool' => [
                                'must' => $filterMatches,
                            ],
                        ];
                        if (true === $filter['includeUnknown']) {
                            $match = [
                                'bool' => [
                                    'should' => [
                                        // either field is within bounds
                                        $match,
                                        // or field is null
                                        [
                                            'bool' => [
                                                'must_not' => [
                                                    'exists' => [
                                                        'field' => $field->getName(),
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'minimum_should_match' => 1,
                                ],
                            ];
                        }
                        $matches[] = $match;
                    }
                break;
                case 'boolean':
                    if ('' !== $filter['v']) {
                        $match = ['term' => [$fieldName => '1' === $filter['v']]];
                        if (true === $filter['includeUnknown']) {
                            $match = [
                                'bool' => [
                                    'should' => [
                                        // either field is as defined
                                        $match,
                                        // or field is null
                                        [
                                            'bool' => [
                                                'must_not' => [
                                                    'exists' => [
                                                        'field' => $field->getName(),
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'minimum_should_match' => 1,
                                ],
                            ];
                        }
                        $matches[] = $match;
                    }
                break;
                case 'string':
                    $filterMatches = [];
                    foreach ($filter['v'] as $value) {
                        $filterMatches[] = ['term' => [$fieldName.'.keyword' => $value]];
                    }
                    if (true === $filter['includeUnknown']) {
                        $filterMatches[] = [
                            'bool' => [
                                'must_not' => [
                                    'exists' => [
                                        'field' => $field->getName(),
                                    ],
                                ],
                            ],
                        ];
                    }
                    if (!empty($filterMatches)) {
                        $matches[] = [
                            'bool' => [
                                'should' => $filterMatches,
                                'minimum_should_match' => 1,
                            ],
                        ];
                    }
                break;
                default:
                    throw new \LogicException('Unsupported field type '.$field->getType());
            }
        }

        return $matches;
    }
}
