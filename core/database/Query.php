<?php

namespace core\database;

use core\database\EQueryDoor;
use core\database\EQueryJoin;
use core\database\EQueryOperator;
use Exception;

// Pas envie de faire 50.000 fichiers pour chaques type
// pour permettre la possibilité de faire des autoloads
require App()->mkPath("core/database/QueryEnums.php");
require App()->mkPath("core/database/QueryConstructors.php");

class Query
{

	private Database $db;

	private bool $useSelect = false;				// SELECT
	private bool $useInsert = false;				// INSERT INTO
	private bool $useUpdate = false;				// UPDATE
	private bool $useDelete = false;				// DELETE
	private bool $useDistinct = false;				// DISTINCT

	private ?string $table = null;					// Table de requête

	/** @var string[] */
	private array $selectList = [];					// Fields de retour

	/** @var QueryInsert[] */
	private array $insert = [];						// Fields d'insertion

	/** @var QueryUpdate[] */
	private array $update = [];						// Fields de mise à jour

	/** @var QueryJoin[] */
	private array $joinList = []; 					// Liste des jointures

	/** @var QueryWhere[] */
	private array $whereList = [];					// Liste des conditions

	private ?int $limit = null;						// Limit le nombre de retour

	private ?int $offset = null;					// Décale le curseur de retour

	/** @var QueryBinding[] */
	private array $bindList = [];					// Liste des valeurs à bind sur la query
	private int $bindIndex = 0;						// Index courant de la valeur a bind

	/** @var string[] */
	private array $queryList = [];					// Liste des query a join()

	public function __construct()
	{
		// Récupère la connexion à la base de données
		$this->db = Database();
	}

	// BASE
	// ====

	/**
	 * Reset les types (statements) de requête
	 */
	private function clearUsed()
	{
		$this->useSelect = false;
		$this->useInsert = false;
		$this->useUpdate = false;
		$this->useDelete = false;
	}

	public function select(string ...$sColumns): self
	{
		$this->clearUsed(); // Reset les types de requête
		$this->selectList = array_merge($this->selectList, $sColumns);
		$this->useSelect = true;
		return $this;
	}

	public function insert(): self
	{
		$this->clearUsed(); // Reset les types de requête
		$this->useInsert = true;
		return $this;
	}

	public function update(): self
	{
		$this->clearUsed(); // Reset les types de requête
		$this->useUpdate = true;
		return $this;
	}

	public function delete(): self
	{
		$this->clearUsed(); // Reset les types de requête
		$this->useDelete = true;
		return $this;
	}

	public function distinct(): self
	{
		$this->useDistinct = true;
		return $this;
	}

	public function table(string $sTable, string $sAlias = null): self
	{
		$this->table = $sTable;
		return $this;
	}

	public function from(string $sTable, ?string $sAlias = null): self
	{
		return $this->table($sTable, $sAlias);
	}

	public function into(string $sTable, ?string $sAlias = null): self
	{
		return $this->table($sTable, $sAlias);
	}

	// JOINTURES
	// =========

	public function join(string $sTable, ?string $sAlias, mixed $mFirstValue, EQueryOperator $sOperator, mixed $mSecondValue, EQueryJoin $sType = EQueryJoin::INNER): self
	{
		$this->joinList[] = new QueryJoin($sTable, $sAlias, $mFirstValue, $sOperator, $mSecondValue, $sType);
		// Build un tableau avec les deux valeurs à bind, puis parcours
		foreach ([$mFirstValue, $mSecondValue] as $mValue) {
			$this->bindIndex++; // Index du bind param au moment de construire la requête préparée
			$this->bindList[] = new QueryBinding($mValue, $this->bindIndex);
		}
		return $this;
	}

	public function leftJoin(string $sTable, ?string $sAlias, mixed $mFirstValue, EQueryOperator $sOperator, mixed $mSecondValue): self
	{
		return $this->join($sTable, $sAlias, $mFirstValue, $sOperator, $mSecondValue, EQueryJoin::LEFT);
	}

	public function rightJoin(string $sTable, ?string $sAlias, mixed $mFirstValue, EQueryOperator $sOperator, mixed $mSecondValue): self
	{
		return $this->join($sTable, $sAlias, $mFirstValue, $sOperator, $mSecondValue, EQueryJoin::RIGHT);
	}

	// CONDITIONS
	// ==========

	public function where(string $sColumn, EQueryOperator $sOperator = EQueryOperator::EQUAL, mixed $mValue = null, ?EQueryDoor $sDoor = null): self
	{
		$this->whereList[] = new QueryWhere($sColumn, $sOperator, $mValue, $sDoor);
		if ($mValue !== null) {
			$this->bindIndex++; // Index du bind param au moment de construire la requête préparée
			$this->bindList[] = new QueryBinding($mValue, $this->bindIndex);
		}
		return $this;
	}

	public function andWhere(string $sColumn, EQueryOperator $sOperator = EQueryOperator::EQUAL, mixed $mValue = null): self
	{
		if (count($this->whereList) == 0) {
			throw new Exception("You must use where() before andWhere()");
		}
		return $this->where($sColumn, $sOperator, $mValue, EQueryDoor::AND );
	}

	public function orWhere(string $sColumn, EQueryOperator $sOperator = EQueryOperator::EQUAL, mixed $mValue = null): self
	{
		if (count($this->whereList) == 0) {
			throw new Exception("You must use where() before orWhere()");
		}
		return $this->where($sColumn, $sOperator, $mValue, EQueryDoor::OR );
	}

	// LIMIT
	// =====

	public function limit(int $nLimit): self
	{
		if ($nLimit > 0) {
			$this->limit = $nLimit;
			return $this;
		} else {
			throw new Exception("Limit must be greater than 0");
		}
	}

	// OFFSET
	// ======

	public function offset(int $nOffset): self
	{
		if ($nOffset > 0) {
			$this->offset = $nOffset;
			return $this;
		} else {
			throw new Exception("Offset must be greater than 0");
		}
	}

	// INSERT
	// ======

	public function insertField(string $sColumn, mixed $mValue): self
	{
		$this->insert[] = new QueryInsert($sColumn, $mValue);
		$this->bindIndex++; // Index du bind param au moment de construire la requête préparée
		$this->bindList[] = new QueryBinding($mValue, $this->bindIndex);
		return $this;
	}

	// UPDATE
	// ======

	public function updateField(string $sColumn, mixed $mValue): self
	{
		$this->update[] = new QueryUpdate($sColumn, $mValue);
		$this->bindIndex++; // Index du bind param au moment de construire la requête préparée
		$this->bindList[] = new QueryUpdate($mValue, $this->bindIndex);
		return $this;
	}

	// BUILDERS
	// ========

	private function buildSelect(): void
	{
		$this->queryList[] = EQueryStatement::SELECT->value;

		if ($this->useDistinct) {
			$this->queryList[] = EQueryClause::DISTINCT->value;
		}

		if (empty($this->selectList)) {
			$this->queryList[] = "*";
		} else {
			$this->queryList[] = implode(",", $this->selectList);
		}

		$this->buildFrom();
	}

	private function buildInsert(): void
	{
		$this->queryList[] = EQueryStatement::INSERT->value;
		$this->buildInto();

		$this->queryList[] = "(" . implode(",", array_map(function ($oInsert) {
			return $oInsert->column;
		}, $this->insert)) . ")";

		$this->queryList[] = EQueryClause::VALUES->value;

		$this->queryList[] = "(" . implode(",", array_map(function ($oInsert) {
			return "?";
		}, $this->insert)) . ")";
	}

	private function buildUpdate(): void
	{
		$this->queryList[] = EQueryStatement::UPDATE->value;
		$this->queryList[] = $this->table;

		$this->queryList[] = EQueryClause::SET->value;

		$this->queryList[] = implode(",", array_map(function ($oUpdate) {
			return $oUpdate->column . " = ?";
		}, $this->update));
	}

	private function buildDelete(): void
	{
		$this->queryList[] = EQueryStatement::DELETE->value;
		$this->buildFrom();
	}

	private function buildFrom(): void
	{
		if ($this->table !== null) {
			$this->queryList[] = EQueryClause::FROM->value;
			$this->queryList[] = $this->table;
		}
	}

	private function buildInto(): void
	{
		if ($this->table !== null) {
			$this->queryList[] = EQueryClause::INTO->value;
			$this->queryList[] = $this->table;
		}
	}


	private function buildJoin(): void
	{
		if ($this->joinList) {
			foreach ($this->joinList as $oJoin) {
				$this->queryList[] = $oJoin->type->value;
				$this->queryList[] = EQueryClause::JOIN->value;
				$this->queryList[] = $oJoin->table;

				if ($oJoin->alias !== null) {
					$this->queryList[] = EQueryClause::AS->value;
					$this->queryList[] = $oJoin->alias;
				}

				$this->queryList[] = EQueryClause::ON->value;
				$this->queryList[] = $oJoin->firstValue;
				$this->queryList[] = $oJoin->operator->value;
				$this->queryList[] = $oJoin->secondValue;
			}
		}
	}

	private function buildWhere(): void
	{
		if ($this->whereList) {
			$this->queryList[] = EQueryClause::WHERE->value;
			foreach ($this->whereList as $oWhere) {
				if ($oWhere->door !== null) {
					$this->queryList[] = $oWhere->door->value;
				}
				$this->queryList[] = $oWhere->column;
				$this->queryList[] = $oWhere->operator->value;
				if ($oWhere->value !== null) {
					$this->queryList[] = "?";
				}
			}
		}
	}

	private function buildLimit(): void
	{
		if ($this->limit) {
			$this->queryList[] = EQueryClause::LIMIT->value;
			$this->queryList[] = $this->limit;
		}
	}

	private function buildOffset(): void
	{
		if ($this->offset) {
			$this->queryList[] = EQueryClause::OFFSET->value;
			$this->queryList[] = $this->offset;
		}
	}

	private function build(): ?string
	{
		if ($this->useSelect) {
			$this->buildSelect();
		}

		if ($this->useInsert) {
			$this->buildInsert();
		}

		if ($this->useUpdate) {
			$this->buildUpdate();
		}

		if ($this->useDelete) {
			$this->buildDelete();
		}

		$this->buildJoin();
		$this->buildWhere();
		$this->buildLimit();
		$this->buildOffset();

		if (empty($this->queryList)) {
			return null;
		}

		return join(" ", $this->queryList);
	}

	// FINAL GETTER
	// ============

	public function getQuery(): ?string
	{
		return $this->build();
	}

	/**
	 * Cette méthode permet de bind une valeur à un paramètre nommé ou non au moment de la requête préparée. Evite les injections SQL.
	 * @param mixed $mValue Valeur à bind
	 * @return Query $this
	 */
	public function bindValue(mixed $mValue): self {
		$this->bindIndex++;
		$this->bindList[] = new QueryBinding($mValue, $this->bindIndex);
		return $this;
	}

	/**
	 * @return QueryBinding[]
	 */
	public function getBindings(): array
	{
		return $this->bindList;
	}

}