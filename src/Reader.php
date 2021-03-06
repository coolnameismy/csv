<?php
/**
* This file is part of the League.csv library
*
* @license http://opensource.org/licenses/MIT
* @link https://github.com/thephpleague/csv/
* @version 9.0.0
* @package League.csv
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/
declare(strict_types=1);

namespace League\Csv;

use BadMethodCallException;
use CallbackFilterIterator;
use Countable;
use Iterator;
use IteratorAggregate;
use JsonSerializable;
use League\Csv\Exception\RuntimeException;
use SplFileObject;

/**
 * A class to manage records selection from a CSV document
 *
 * @package League.csv
 * @since  3.0.0
 *
 * @method array fetchAll() Returns a sequential array of all CSV records
 * @method array fetchOne(int $offset = 0) Returns a single record from the CSV
 * @method Generator fetchColumn(string|int $column_index) Returns the next value from a single CSV record field
 * @method Generator fetchPairs(string|int $offset_index, string|int $value_index) Fetches the next key-value pairs from the CSV document
 */
class Reader extends AbstractCsv implements Countable, IteratorAggregate, JsonSerializable
{
    /**
     * CSV Document header offset
     *
     * @var int|null
     */
    protected $header_offset;

    /**
     * CSV Document Header record
     *
     * @var string[]
     */
    protected $header = [];

    /**
     * Records count
     *
     * @var int
     */
    protected $nb_records = -1;

    /**
     * @inheritdoc
     */
    protected $stream_filter_mode = STREAM_FILTER_READ;

    /**
     * Returns the record offset used as header
     *
     * If no CSV record is used this method MUST return null
     *
     * @return int|null
     */
    public function getHeaderOffset()
    {
        return $this->header_offset;
    }

    /**
     * Returns the CSV record header
     *
     * The returned header is represented as an array of string values
     *
     * @return string[]
     */
    public function getHeader(): array
    {
        if (null === $this->header_offset) {
            return $this->header;
        }

        if (empty($this->header)) {
            $this->header = $this->setHeader($this->header_offset);
        }

        return $this->header;
    }

    /**
     * Determine the CSV record header
     *
     * @param int $offset
     *
     * @throws RuntimeException If the header offset is an integer
     *                          and the corresponding record is missing
     *                          or is an empty array
     *
     * @return string[]
     */
    protected function setHeader(int $offset): array
    {
        $this->document->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
        $this->document->seek($offset);
        $header = $this->document->current();
        if (empty($header)) {
            throw new RuntimeException(sprintf('The header record does not exist or is empty at offset: `%s`', $offset));
        }

        if (0 === $offset) {
            return $this->removeBOM($header, mb_strlen($this->getInputBOM()), $this->getEnclosure());
        }

        return $header;
    }

    /**
     * Strip the BOM sequence from a record
     *
     * @param string[] $record
     * @param int      $bom_length
     * @param string   $enclosure
     *
     * @return string[]
     */
    protected function removeBOM(array $record, int $bom_length, string $enclosure): array
    {
        if (0 == $bom_length) {
            return $record;
        }

        $record[0] = mb_substr($record[0], $bom_length);
        if ($enclosure == mb_substr($record[0], 0, 1) && $enclosure == mb_substr($record[0], -1, 1)) {
            $record[0] = mb_substr($record[0], 1, -1);
        }

        return $record;
    }

    /**
     * @inheritdoc
     */
    public function __call($method, array $arguments)
    {
        $whitelisted = ['fetchColumn' => 1, 'fetchPairs' => 1, 'fetchOne' => 1, 'fetchAll' => 1];
        if (isset($whitelisted[$method])) {
            return (new ResultSet($this->getRecords(), $this->getHeader()))->$method(...$arguments);
        }

        throw new BadMethodCallException(sprintf('%s::%s() method does not exist', __CLASS__, $method));
    }

    /**
     * @inheritdoc
     */
    public function count(): int
    {
        if (-1 === $this->nb_records) {
            $this->nb_records = iterator_count($this->getRecords());
        }

        return $this->nb_records;
    }

    /**
     * @inheritdoc
     */
    public function getIterator(): Iterator
    {
        return $this->getRecords();
    }

    /**
     * @inheritdoc
     */
    public function jsonSerialize(): array
    {
        return iterator_to_array($this->getRecords(), false);
    }

    /**
     * Returns the CSV records in an iterator object.
     *
     * Each CSV record is represented as a simple array of string or null values.
     *
     * If the CSV document has a header record then each record is combined
     * to each header record and the header record is removed from the iterator.
     *
     * If the CSV document is inconsistent. Missing record fields are
     * filled with null values while extra record fields are strip from
     * the returned object.
     *
     * @param string[] $header an optional header to use instead of the CSV document header
     *
     * @return Iterator
     */
    public function getRecords(array $header = []): Iterator
    {
        $header = $this->computeHeader($header);
        $normalized = function ($record): bool {
            return is_array($record) && $record != [null];
        };
        $bom = $this->getInputBOM();
        $this->document->setFlags(SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);

        $records = $this->stripBOM(new CallbackFilterIterator($this->document, $normalized), $bom);
        if (null !== $this->header_offset) {
            $records = new CallbackFilterIterator($records, function (array $record, int $offset): bool {
                return $offset !== $this->header_offset;
            });
        }

        return $this->combineHeader($records, $header);
    }

    /**
     * Returns the header to be used for iteration
     *
     * @param string[] $header
     *
     * @throws RuntimeException If the header contains non unique column name
     *
     * @return string[]
     */
    protected function computeHeader(array $header)
    {
        if (empty($header)) {
            $header = $this->getHeader();
        }

        if ($header === array_unique(array_filter($header, 'is_string'))) {
            return $header;
        }

        throw new RuntimeException('The header record must be empty or a flat array with unique string values');
    }

    /**
     * Add the CSV header if present and valid
     *
     * @param Iterator $iterator
     * @param string[] $header
     *
     * @return Iterator
     */
    protected function combineHeader(Iterator $iterator, array $header): Iterator
    {
        if (empty($header)) {
            return $iterator;
        }

        $field_count = count($header);
        $mapper = function (array $record) use ($header, $field_count): array {
            if (count($record) != $field_count) {
                $record = array_slice(array_pad($record, $field_count, null), 0, $field_count);
            }

            return array_combine($header, $record);
        };

        return new MapIterator($iterator, $mapper);
    }

    /**
     * Strip the BOM sequence if present
     *
     * @param Iterator $iterator
     * @param string   $bom
     *
     * @return Iterator
     */
    protected function stripBOM(Iterator $iterator, string $bom): Iterator
    {
        if ('' === $bom) {
            return $iterator;
        }

        $bom_length = mb_strlen($bom);
        $mapper = function (array $record, int $index) use ($bom_length): array {
            if (0 != $index) {
                return $record;
            }

            return $this->removeBOM($record, $bom_length, $this->getEnclosure());
        };

        return new MapIterator($iterator, $mapper);
    }

    /**
     * Selects the record to be used as the CSV header
     *
     * Because of the header is represented as an array, to be valid
     * a header MUST contain only unique string value.
     *
     * @param int|null $offset the header record offset
     *
     * @return static
     */
    public function setHeaderOffset($offset): self
    {
        if (null !== $offset) {
            $offset = $this->filterMinRange($offset, 0, __METHOD__.'() expects the header offset index to be a positive integer or 0, %s given');
        }

        if ($offset !== $this->header_offset) {
            $this->header_offset = $offset;
            $this->resetProperties();
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    protected function resetProperties()
    {
        $this->nb_records = -1;
        $this->header = [];
    }
}
