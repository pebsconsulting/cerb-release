<?php
class DAO_AbstractCustomRecord extends Cerb_ORMHelper {
	const _ID = 0; // overridden by subclass
	
	const ID = 'id';
	const NAME = 'name';
	const OWNER_CONTEXT = 'owner_context';
	const OWNER_CONTEXT_ID = 'owner_context_id';
	const UPDATED_AT = 'updated_at';
	
	static function getFields() {
		$validation = DevblocksPlatform::services()->validation();
		
		$validation
			->addField(self::ID)
			->id()
			->setEditable(false)
			;
		$validation
			->addField(self::NAME)
			->string()
			->setMaxLength(255)
			->setRequired(true)
			;
		$validation
			->addField(self::OWNER_CONTEXT)
			->string()
			->addFormatter($validation->formatters()->context(true))
			->addValidator($validation->validators()->context(true))
			;
		$validation
			->addField(self::OWNER_CONTEXT_ID)
			->id()
			;
		$validation
			->addField(self::UPDATED_AT)
			->timestamp()
			;
		$validation
			->addField('_links')
			->string()
			->setMaxLength(65535)
			;

		return $validation->getFields();
	}
	
	static private function _getContextName() {
		return 'contexts.custom_record.' . static::_ID;
	}
	
	static private function _getTableName() {
		return 'custom_record_' . static::_ID;
	}

	static function create($fields) {
		$db = DevblocksPlatform::services()->database();
		
		$table_name = self::_getTableName();
		
		$sql = sprintf("INSERT INTO %s () VALUES ()",
			$db->escape($table_name)
		);
		$db->ExecuteMaster($sql);
		
		$id = $db->LastInsertId();
		
		self::update($id, $fields);
		
		return $id;
	}
	
	static function update($ids, $fields, $check_deltas=true) {
		$table_name = self::_getTableName();
		$context_name = self::_getContextName();
		
		if(!is_array($ids))
			$ids = array($ids);
		
		if(!isset($fields[self::UPDATED_AT]))
			$fields[self::UPDATED_AT] = time();
		
		self::_updateAbstract(self::_getContextName(), $ids, $fields);
		
		// Make a diff for the requested objects in batches
		
		$chunks = array_chunk($ids, 100, true);
		while($batch_ids = array_shift($chunks)) {
			if(empty($batch_ids))
				continue;
				
			// Send events
			if($check_deltas) {
				CerberusContexts::checkpointChanges($context_name, $batch_ids);
			}
			
			// Make changes
			parent::_update($batch_ids, $table_name, $fields);
			
			// Send events
			if($check_deltas) {
				// Trigger an event about the changes
				$eventMgr = DevblocksPlatform::services()->event();
				$eventMgr->trigger(
					new Model_DevblocksEvent(
						sprintf('dao.%s.update', $table_name),
						array(
							'fields' => $fields,
						)
					)
				);
				
				// Log the context update
				DevblocksPlatform::markContextChanged($context_name, $batch_ids);
			}
		}
	}
	
	static function updateWhere($fields, $where) {
		$table_name = self::_getTableName();
		parent::_updateWhere($table_name, $fields, $where);
	}
	
	static public function onBeforeUpdateByActor($actor, $fields, $id=null, &$error=null) {
		$context = self::_getContextName();
		
		// Check context privs
		if(!self::_onBeforeUpdateByActorCheckContextPrivs($actor, $context, $id, $error))
			return false;
		
		// Check the custom record possible owners
		
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID))) {
			$error = "Invalid record type.";
			return false;
		}
		
		@$owner_contexts = $custom_record->params['owners']['contexts'] ?: [];
		
		@$owner_context = $fields[self::OWNER_CONTEXT];
		@$owner_context_id = $fields[self::OWNER_CONTEXT_ID];
		
		// If this custom record doesn't have ownership
		if(empty($owner_contexts)) {
			// But we provided an owner
			if($owner_context || $owner_context_id) {
				$error = "This record type doesn't have ownership.";
				return false;
			}
			
		} else {
			// If creating, we must provide an owner
			if((!$id && $owner_contexts && !$owner_context)) {
				$error = sprintf("'owner__context' is required and must be one of: %s", implode(', ', $owner_contexts));
				return false;
			}
			
			// If we're providing an owner
			if($owner_context) {
				// The owner must be of the right type
				if(!in_array($owner_context, $owner_contexts)) {
					$error = sprintf("'owner__context' must be one of: %s", implode(', ', $owner_contexts));
					return false;
				}
				
				// The owner must be something this actor can use
				if(!CerberusContexts::isOwnableBy($owner_context, $owner_context_id, $actor)) {
					$error = DevblocksPlatform::translate('error.core.no_acl.owner');
					return false;
				}
			}
		}
		
		return true;
	}
	
	/**
	 * @param string $where
	 * @param mixed $sortBy
	 * @param mixed $sortAsc
	 * @param integer $limit
	 * @return Model_AbstractCustomRecord[]
	 */
	static function getWhere($where=null, $sortBy=null, $sortAsc=true, $limit=null, $options=null) {
		$db = DevblocksPlatform::services()->database();
		$table_name = self::_getTableName();

		list($where_sql, $sort_sql, $limit_sql) = self::_getWhereSQL($where, $sortBy, $sortAsc, $limit);
		
		// SQL
		$sql = "SELECT id, name, owner_context, owner_context_id, updated_at ".
			sprintf("FROM %s ", $db->escape($table_name)) .
			$where_sql.
			$sort_sql.
			$limit_sql
		;
		
		if($options & Cerb_ORMHelper::OPT_GET_MASTER_ONLY) {
			$rs = $db->ExecuteMaster($sql, _DevblocksDatabaseManager::OPT_NO_READ_AFTER_WRITE);
		} else {
			$rs = $db->ExecuteSlave($sql);
		}
		
		return self::_getObjectsFromResult($rs);
	}
	
	/**
	 *
	 * @param bool $nocache
	 * @return Model_AbstractCustomRecord[]
	 */
	static function getAll($nocache=false) {
		//$cache = DevblocksPlatform::services()->cache();
		//if($nocache || null === ($objects = $cache->load(self::_CACHE_ALL))) {
			$objects = self::getWhere(null, DAO_AbstractCustomRecord::NAME, true, null, Cerb_ORMHelper::OPT_GET_MASTER_ONLY);
			
			//if(!is_array($objects))
			//	return false;
				
			//$cache->save($objects, self::_CACHE_ALL);
		//}
		
		return $objects;
	}

	/**
	 * @param integer $id
	 * @return Model_AbstractCustomRecord
	 */
	static function get($id) {
		if(empty($id))
			return null;
		
		$objects = self::getWhere(sprintf("%s = %d",
			self::ID,
			$id
		));
		
		if(isset($objects[$id]))
			return $objects[$id];
		
		return null;
	}
	
	/**
	 * 
	 * @param array $ids
	 * @return Model_AbstractCustomRecord[]
	 */
	static function getIds($ids) {
		if(!is_array($ids))
			$ids = array($ids);

		if(empty($ids))
			return array();

		if(!method_exists(get_called_class(), 'getWhere'))
			return array();

		$db = DevblocksPlatform::services()->database();

		$ids = DevblocksPlatform::importVar($ids, 'array:integer');

		$models = array();

		$results = static::getWhere(sprintf("id IN (%s)",
			implode(',', $ids)
		));

		// Sort $models in the same order as $ids
		foreach($ids as $id) {
			if(isset($results[$id]))
				$models[$id] = $results[$id];
		}

		unset($results);

		return $models;
	}	
	
	/**
	 * @param resource $rs
	 * @return Model_AbstractCustomRecord[]
	 */
	static private function _getObjectsFromResult($rs) {
		$objects = array();
		
		if(!($rs instanceof mysqli_result))
			return false;
		
		while($row = mysqli_fetch_assoc($rs)) {
			$object = new Model_AbstractCustomRecord();
			$object->id = intval($row['id']);
			$object->name = $row['name'];
			$object->owner_context = $row['owner_context'];
			$object->owner_context_id = intval($row['owner_context_id']);
			$object->updated_at = intval($row['updated_at']);
			$objects[$object->id] = $object;
		}
		
		mysqli_free_result($rs);
		
		return $objects;
	}
	
	static function random() {
		$table_name = self::_getTableName();
		return self::_getRandom($table_name);
	}
	
	static function count() {
		$db = DevblocksPlatform::services()->database();
		
		$sql = sprintf("SELECT COUNT(id) FROM custom_record_%d",
			static::_ID
		);
		
		return $db->getOneSlave($sql);
	}
	
	static function clearOtherOwners($owners) {
		$db = DevblocksPlatform::services()->database();
		
		$contexts = [
			CerberusContexts::CONTEXT_APPLICATION,
			CerberusContexts::CONTEXT_ROLE,
			CerberusContexts::CONTEXT_GROUP,
			CerberusContexts::CONTEXT_WORKER,
		];
		
		$remove_contexts = array_diff($contexts, $owners);
		
		if(empty($remove_contexts))
			return;
		
		if(1 == count($owners) && in_array(CerberusContexts::CONTEXT_APPLICATION, $owners)) {
			$remove_contexts[] = '';
			$default_owner = CerberusContexts::CONTEXT_APPLICATION;
			$default_owner_id = 0;
		
		} else {
			$default_owner = '';
			$default_owner_id = 0;
		}
		
		$sql = sprintf("UPDATE custom_record_%d SET owner_context = %s, owner_context_id = %d WHERE owner_context IN (%s)",
			static::_ID,
			$db->qstr($default_owner),
			$default_owner_id,
			implode(',', $db->qstrArray($remove_contexts))
		);
		
		return $db->Execute($sql);
	}
	
	static function delete($ids) {
		$db = DevblocksPlatform::services()->database();
		
		if(!is_array($ids))
			$ids = array($ids);
		
		$table_name = self::_getTableName();
		$context_name = self::_getContextName();
		
		if(empty($ids))
			return;
		
		$ids_list = implode(',', $ids);
		
		$db->ExecuteMaster(sprintf("DELETE FROM %s WHERE id IN (%s)",
			$db->escape($table_name),
			$ids_list
		));
		
		// Fire event
		$eventMgr = DevblocksPlatform::services()->event();
		$eventMgr->trigger(
			new Model_DevblocksEvent(
				'context.delete',
				array(
					'context' => $context_name,
					'context_ids' => $ids
				)
			)
		);
		
		return true;
	}
	
	public static function getSearchQueryComponents($columns, $params, $sortBy=null, $sortAsc=null) {
		$search_class = sprintf("SearchFields_AbstractCustomRecord_%d", static::_ID);
		
		$fields = $search_class::getFields();
		
		list($tables,$wheres) = parent::_parseSearchParams($params, $columns, $search_class, $sortBy);
		
		$table_name = self::_getTableName();
		
		$select_sql = sprintf("SELECT ".
			"id as %s, ".
			"name as %s, ".
			"owner_context as %s, ".
			"owner_context_id as %s, ".
			"updated_at as %s ",
				SearchFields_AbstractCustomRecord::ID,
				SearchFields_AbstractCustomRecord::NAME,
				SearchFields_AbstractCustomRecord::OWNER_CONTEXT,
				SearchFields_AbstractCustomRecord::OWNER_CONTEXT_ID,
				SearchFields_AbstractCustomRecord::UPDATED_AT
			);
			
		$join_sql = sprintf("FROM %s ",
			self::escape($table_name)
		);
		
		$where_sql = "".
			(!empty($wheres) ? sprintf("WHERE %s ",implode(' AND ',$wheres)) : "WHERE 1 ");
			
		$sort_sql = self::_buildSortClause($sortBy, $sortAsc, $fields, $select_sql, $search_class);
	
		// Virtuals
		
		$args = array(
			'join_sql' => &$join_sql,
			'where_sql' => &$where_sql,
			'tables' => &$tables,
		);
	
		array_walk_recursive(
			$params,
			array('DAO_AbstractCustomRecord', '_translateVirtualParameters'),
			$args
		);
		
		return array(
			'primary_table' => $table_name,
			'select' => $select_sql,
			'join' => $join_sql,
			'where' => $where_sql,
			'sort' => $sort_sql,
		);
	}
	
	private static function _translateVirtualParameters($param, $key, &$args) {
		if(!is_a($param, 'DevblocksSearchCriteria'))
			return;
		
		$table_name = self::_getTableName();
		$context_name = self::_getContextName();
			
		$from_context = $context_name;
		$from_index = sprintf('%s.id', self::escape($table_name));
		
		$param_key = $param->field;
		settype($param_key, 'string');
		
		switch($param_key) {
			case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				self::_searchComponentsVirtualHasFieldset($param, $from_context, $from_index, $args['join_sql'], $args['where_sql']);
				break;
		}
	}
	
	static function autocomplete($term, $as='models') {
		$db = DevblocksPlatform::services()->database();
		
		$objects = [];
		$table_name = self::_getTableName();
		
		$results = $db->GetArraySlave(sprintf("SELECT id ".
			"FROM %s ".
			"WHERE ".
			"name LIKE %s ".
			"ORDER BY name ".
			"LIMIT 25 ",
			$db->escape($table_name),
			$db->qstr($term.'%')
		));
		
		if(is_array($results))
		foreach($results as $row) {
			$objects[$row['id']] = null;
		}
		
		switch($as) {
			case 'ids':
				return array_keys($objects);
				break;
				
			default:
				return self::getIds(array_keys($objects));
				break;
		}
	}
	
	/**
	 * Search records
	 *
	 * @param array $columns
	 * @param DevblocksSearchCriteria[] $params
	 * @param integer $limit
	 * @param integer $page
	 * @param string $sortBy
	 * @param boolean $sortAsc
	 * @param boolean $withCounts
	 * @return array
	 */
	static function search($columns, $params, $limit=10, $page=0, $sortBy=null, $sortAsc=null, $withCounts=true) {
		$db = DevblocksPlatform::services()->database();
		
		$table_name = self::_getTableName();
		
		// Build search queries
		$query_parts = self::getSearchQueryComponents($columns,$params,$sortBy,$sortAsc);

		$select_sql = $query_parts['select'];
		$join_sql = $query_parts['join'];
		$where_sql = $query_parts['where'];
		$sort_sql = $query_parts['sort'];
		
		$sql =
			$select_sql.
			$join_sql.
			$where_sql.
			$sort_sql;
			
		if($limit > 0) {
			if(false == ($rs = $db->SelectLimit($sql,$limit,$page*$limit)))
				return false;
		} else {
			if(false == ($rs = $db->ExecuteSlave($sql)))
				return false;
			$total = mysqli_num_rows($rs);
		}
		
		if(!($rs instanceof mysqli_result))
			return false;
		
		$results = array();
		
		while($row = mysqli_fetch_assoc($rs)) {
			$object_id = intval($row[SearchFields_AbstractCustomRecord::ID]);
			$results[$object_id] = $row;
		}

		$total = count($results);
		
		if($withCounts) {
			// We can skip counting if we have a less-than-full single page
			if(!(0 == $page && $total < $limit)) {
				$count_sql =
					sprintf("SELECT COUNT(%s.id) ", self::escape($table_name)).
					$join_sql.
					$where_sql;
				$total = $db->GetOneSlave($count_sql);
			}
		}
		
		mysqli_free_result($rs);
		
		return array($results,$total);
	}
};

class SearchFields_AbstractCustomRecord extends DevblocksSearchFields {
	const _ID = 0; // overridden by subclass
	
	const ID = 'a_id';
	const NAME = 'a_name';
	const OWNER_CONTEXT = 'a_owner_context';
	const OWNER_CONTEXT_ID = 'a_owner_context_id';
	const UPDATED_AT = 'a_updated_at';

	const VIRTUAL_CONTEXT_LINK = '*_context_link';
	const VIRTUAL_HAS_FIELDSET = '*_has_fieldset';
	const VIRTUAL_OWNER = '*_owner';
	const VIRTUAL_WATCHERS = '*_workers';
	
	static private function _getContextName() {
		return 'contexts.custom_record.' . static::_ID;
	}
	
	static private function _getTableName() {
		return 'custom_record_' . static::_ID;
	}
	
	static function getPrimaryKey() {
		$table_name = self::_getTableName();
		
		return sprintf('%s.id',
			Cerb_ORMHelper::escape($table_name)
		);
	}
	
	static function getCustomFieldContextKeys() {
		$table_name = self::_getTableName();
		$context = self::_getContextName();
		
		return array(
			$context => new DevblocksSearchFieldContextKeys(sprintf('%s.id', Cerb_ORMHelper::escape($table_name)), self::ID),
		);
	}
	
	static function getWhereSQL(DevblocksSearchCriteria $param) {
		$table_name = self::_getTableName();
		$context_name = self::_getContextName();
		
		switch($param->field) {
			case self::VIRTUAL_CONTEXT_LINK:
				return self::_getWhereSQLFromContextLinksField($param, $context_name, self::getPrimaryKey());
				break;
				
			case self::VIRTUAL_OWNER:
				return self::_getWhereSQLFromContextAndID($param, sprintf('%s.owner_context', Cerb_ORMHelper::escape($table_name)), sprintf('%s.owner_context_id', Cerb_ORMHelper::escape($table_name)));
				break;
				
			case self::VIRTUAL_WATCHERS:
				return self::_getWhereSQLFromWatchersField($param, '', self::getPrimaryKey());
				break;
			
			default:
				if('cf_' == substr($param->field, 0, 3)) {
					return self::_getWhereSQLFromCustomFields($param);
				} else {
					return $param->getWhereSQL(self::getFields(), self::getPrimaryKey());
				}
				break;
		}
	}
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function getFields() {
		return static::_getFields();
	}
	
	/**
	 * @return DevblocksSearchField[]
	 */
	static function _getFields() {
		$translate = DevblocksPlatform::getTranslationService();
		
		$table_name = self::_getTableName();
		
		$columns = array(
			self::ID => new DevblocksSearchField(self::ID, $table_name, 'id', $translate->_('common.id'), null, true),
			self::NAME => new DevblocksSearchField(self::NAME, $table_name, 'name', $translate->_('common.name'), null, true),
			self::OWNER_CONTEXT => new DevblocksSearchField(self::OWNER_CONTEXT, $table_name, 'owner_context', $translate->_('common.owner_context'), null, true),
			self::OWNER_CONTEXT_ID => new DevblocksSearchField(self::OWNER_CONTEXT_ID, $table_name, 'owner_context_id', $translate->_('common.owner_context_id'), null, true),
			self::UPDATED_AT => new DevblocksSearchField(self::UPDATED_AT, $table_name, 'updated_at', $translate->_('common.updated'), null, true),
			
			self::VIRTUAL_CONTEXT_LINK => new DevblocksSearchField(self::VIRTUAL_CONTEXT_LINK, '*', 'context_link', $translate->_('common.links'), null, false),
			self::VIRTUAL_HAS_FIELDSET => new DevblocksSearchField(self::VIRTUAL_HAS_FIELDSET, '*', 'has_fieldset', $translate->_('common.fieldset'), null, false),
			self::VIRTUAL_OWNER => new DevblocksSearchField(self::VIRTUAL_OWNER, '*', 'owner', $translate->_('common.owner'), null, false),
			self::VIRTUAL_WATCHERS => new DevblocksSearchField(self::VIRTUAL_WATCHERS, '*', 'workers', $translate->_('common.watchers'), 'WS', false),
		);
		
		// Custom Fields
		$custom_columns = DevblocksSearchField::getCustomSearchFieldsByContexts(array_keys(self::getCustomFieldContextKeys()));
		
		if(!empty($custom_columns))
			$columns = array_merge($columns, $custom_columns);
		
		// Sort by label (translation-conscious)
		DevblocksPlatform::sortObjects($columns, 'db_label');
		
		return $columns;
	}
};

class Model_AbstractCustomRecord {
	public $id;
	public $name;
	public $owner_context;
	public $owner_context_id;
	public $updated_at;
};

class View_AbstractCustomRecord extends C4_AbstractView implements IAbstractView_Subtotals, IAbstractView_QuickSearch {
	const _ID = 0; // overridden by subclass
	
	static private function _getContextName() {
		return 'contexts.custom_record.' . static::_ID;
	}
	
	static private function _getTableName() {
		return 'custom_record_' . static::_ID;
	}

	function __construct() {
		if(false == ($record = DAO_CustomRecord::get(static::_ID)))
			return false;
		
		$this->id = self::_getTableName();
		$this->name = DevblocksPlatform::translateCapitalized($record->name);
		$this->renderLimit = 25;
		$this->renderSortBy = SearchFields_AbstractCustomRecord::ID;
		$this->renderSortAsc = true;

		$this->view_columns = array(
			SearchFields_AbstractCustomRecord::NAME,
			SearchFields_AbstractCustomRecord::VIRTUAL_OWNER,
			SearchFields_AbstractCustomRecord::UPDATED_AT,
		);
		
		$this->addColumnsHidden(array(
			SearchFields_AbstractCustomRecord::OWNER_CONTEXT,
			SearchFields_AbstractCustomRecord::OWNER_CONTEXT_ID,
			SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK,
			SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET,
			SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS,
		));
		
		$this->addParamsHidden(array(
			SearchFields_AbstractCustomRecord::OWNER_CONTEXT,
			SearchFields_AbstractCustomRecord::OWNER_CONTEXT_ID,
		));
		
		$this->doResetCriteria();
	}

	function getData() {
		$dao_class = sprintf("DAO_AbstractCustomRecord_%d", static::_ID);
		
		$objects = $dao_class::search(
			$this->view_columns,
			$this->getParams(),
			$this->renderLimit,
			$this->renderPage,
			$this->renderSortBy,
			$this->renderSortAsc,
			$this->renderTotal
		);
		
		$this->_lazyLoadCustomFieldsIntoObjects($objects, 'SearchFields_AbstractCustomRecord_' . static::_ID);
		
		return $objects;
	}
	
	function getDataAsObjects($ids=null) {
		return $this->_getDataAsObjects('DAO_AbstractCustomRecord_' . static::_ID, $ids);
	}
	
	function getDataSample($size) {
		return $this->_doGetDataSample('DAO_AbstractCustomRecord_' . static::_ID, $size);
	}

	function getSubtotalFields() {
		$all_fields = $this->getParamsAvailable(true);
		
		$fields = array();

		if(is_array($all_fields))
		foreach($all_fields as $field_key => $field_model) {
			$pass = false;
			
			switch($field_key) {
				// Fields
				//case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				//	$pass = true;
				//	break;
					
				// Virtuals
				case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				case SearchFields_AbstractCustomRecord::VIRTUAL_OWNER:
				case SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS:
					$pass = true;
					break;
					
				// Valid custom fields
				default:
					if(DevblocksPlatform::strStartsWith($field_key, 'cf_'))
						$pass = $this->_canSubtotalCustomField($field_key);
					break;
			}
			
			if($pass)
				$fields[$field_key] = $field_model;
		}
		
		return $fields;
	}
	
	function getSubtotalCounts($column) {
		$counts = [];
		$fields = $this->getFields();
		$context = self::_getContextName();

		if(!isset($fields[$column]))
			return array();
		
		switch($column) {
			case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				$counts = $this->_getSubtotalCountForContextLinkColumn($context, $column);
				break;

			case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				$counts = $this->_getSubtotalCountForHasFieldsetColumn($context, $column);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_OWNER:
				$counts = $this->_getSubtotalCountForContextAndIdColumns($context, $column, DAO_AbstractCustomRecord::OWNER_CONTEXT, DAO_AbstractCustomRecord::OWNER_CONTEXT_ID, 'owner_context[]');
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS:
				$counts = $this->_getSubtotalCountForWatcherColumn($context, $column);
				break;
			
			default:
				// Custom fields
				if(DevblocksPlatform::strStartsWith($column, 'cf_')) {
					$counts = $this->_getSubtotalCountForCustomColumn($context, $column);
				}
				
				break;
		}
		
		return $counts;
	}
	
	function getQuickSearchFields() {
		$search_class = sprintf("SearchFields_AbstractCustomRecord_%d", static::_ID);
		$search_fields = $search_class::getFields();
		$context = self::_getContextName();
	
		$fields = array(
			'text' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => $search_class::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'id' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_NUMBER,
					'options' => array('param_key' => $search_class::ID),
					'examples' => [
						['type' => 'chooser', 'context' => $context, 'q' => ''],
					]
				),
			'name' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_TEXT,
					'options' => array('param_key' => $search_class::NAME, 'match' => DevblocksSearchCriteria::OPTION_TEXT_PARTIAL),
				),
			'owner' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_VIRTUAL,
					'options' => array('param_key' => $search_class::VIRTUAL_OWNER),
				),
			'updated' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_DATE,
					'options' => array('param_key' => $search_class::UPDATED_AT),
				),
			'watchers' => 
				array(
					'type' => DevblocksSearchCriteria::TYPE_WORKER,
					'options' => array('param_key' => $search_class::VIRTUAL_WATCHERS),
				),
		);
		
		// Add quick search links
		
		$fields = self::_appendVirtualFiltersFromQuickSearchContexts('links', $fields, 'links');
		
		// Add searchable custom fields
		
		$fields = self::_appendFieldsFromQuickSearchContext($context, $fields, null);
		
		// Add is_sortable
		
		$fields = self::_setSortableQuickSearchFields($fields, $search_fields);
		
		// Sort by keys
		ksort($fields);
		
		return $fields;
	}	
	
	function getParamFromQuickSearchFieldTokens($field, $tokens) {
		switch($field) {
			default:
				if($field == 'owner' || substr($field, 0, strlen('owner.')) == 'owner.')
					return DevblocksSearchCriteria::getVirtualContextParamFromTokens($field, $tokens, 'owner', SearchFields_AbstractCustomRecord::VIRTUAL_OWNER);
				
				if($field == 'links' || substr($field, 0, 6) == 'links.')
					return DevblocksSearchCriteria::getContextLinksParamFromTokens($field, $tokens);
				
				$search_fields = $this->getQuickSearchFields();
				return DevblocksSearchCriteria::getParamFromQueryFieldTokens($field, $tokens, $search_fields);
				break;
		}
		
		return false;
	}
	
	function render() {
		$this->_sanitize();
		
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('id', $this->id);
		$tpl->assign('view', $this);

		// Custom record
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		$tpl->assign('custom_record', $custom_record);
		
		// Custom fields
		$custom_fields = DAO_CustomField::getByContext(self::_getContextName());
		$tpl->assign('custom_fields', $custom_fields);

		$tpl->assign('view_context', $custom_record->getContext());
		$tpl->assign('view_template', 'devblocks:cerberusweb.core::internal/abstract_custom_record/view.tpl');
		$tpl->display('devblocks:cerberusweb.core::internal/views/subtotals_and_view.tpl');
	}

	function renderCriteria($field) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('id', $this->id);
		
		switch($field) {
			case SearchFields_AbstractCustomRecord::NAME:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__string.tpl');
				break;
				
			case SearchFields_AbstractCustomRecord::ID:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__number.tpl');
				break;
				
			case 'placeholder_bool':
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__bool.tpl');
				break;
				
			case SearchFields_AbstractCustomRecord::UPDATED_AT:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__date.tpl');
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				$contexts = Extension_DevblocksContext::getAll(false);
				$tpl->assign('contexts', $contexts);
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_link.tpl');
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				$this->_renderCriteriaHasFieldset($tpl, 'cerberusweb.contexts.abstract.custom.record');
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_OWNER:
				$groups = DAO_Group::getAll();
				$tpl->assign('groups', $groups);
				
				$roles = DAO_WorkerRole::getAll();
				$tpl->assign('roles', $roles);
				
				$workers = DAO_Worker::getAll();
				$tpl->assign('workers', $workers);
				
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_owner.tpl');
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS:
				$tpl->display('devblocks:cerberusweb.core::internal/views/criteria/__context_worker.tpl');
				break;
				
			default:
				// Custom Fields
				if('cf_' == substr($field,0,3)) {
					$this->_renderCriteriaCustomField($tpl, substr($field,3));
				} else {
					echo ' ';
				}
				break;
		}
	}

	function renderCriteriaParam($param) {
		$field = $param->field;
		$values = !is_array($param->value) ? array($param->value) : $param->value;

		switch($field) {
			default:
				parent::renderCriteriaParam($param);
				break;
		}
	}

	function renderVirtualCriteria($param) {
		$key = $param->field;
		
		$translate = DevblocksPlatform::getTranslationService();
		
		switch($key) {
			case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				$this->_renderVirtualContextLinks($param);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				$this->_renderVirtualHasFieldset($param);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_OWNER:
				$this->_renderVirtualContextLinks($param, 'Owner', 'Owners', 'Owner matches');
				break;
			
			case SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS:
				$this->_renderVirtualWatchers($param);
				break;
		}
	}

	function getFields() {
		$search_class = sprintf("SearchFields_AbstractCustomRecord_%d", static::_ID);
		return $search_class::getFields();
	}

	function doSetCriteria($field, $oper, $value) {
		$criteria = null;

		switch($field) {
			case SearchFields_AbstractCustomRecord::NAME:
				$criteria = $this->_doSetCriteriaString($field, $oper, $value);
				break;
				
			case SearchFields_AbstractCustomRecord::ID:
				$criteria = new DevblocksSearchCriteria($field,$oper,$value);
				break;
				
			case SearchFields_AbstractCustomRecord::UPDATED_AT:
				$criteria = $this->_doSetCriteriaDate($field, $oper);
				break;
				
			case 'placeholder_bool':
				@$bool = DevblocksPlatform::importGPC($_REQUEST['bool'],'integer',1);
				$criteria = new DevblocksSearchCriteria($field,$oper,$bool);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK:
				@$context_links = DevblocksPlatform::importGPC($_REQUEST['context_link'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$context_links);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_HAS_FIELDSET:
				@$options = DevblocksPlatform::importGPC($_REQUEST['options'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,DevblocksSearchCriteria::OPER_IN,$options);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_OWNER:
				@$owner_contexts = DevblocksPlatform::importGPC($_REQUEST['owner_context'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$owner_contexts);
				break;
				
			case SearchFields_AbstractCustomRecord::VIRTUAL_WATCHERS:
				@$worker_ids = DevblocksPlatform::importGPC($_REQUEST['worker_id'],'array',array());
				$criteria = new DevblocksSearchCriteria($field,$oper,$worker_ids);
				break;
				
			default:
				// Custom Fields
				if(substr($field,0,3)=='cf_') {
					$criteria = $this->_doSetCriteriaCustomField($field, substr($field,3));
				}
				break;
		}

		if(!empty($criteria)) {
			$this->addParam($criteria, $field);
			$this->renderPage = 0;
		}
	}
};

class Context_AbstractCustomRecord extends Extension_DevblocksContext implements IDevblocksContextProfile, IDevblocksContextPeek, IDevblocksContextAutocomplete, IDevblocksContextImport {
	const _ID = 0; // overridden by subclass
	const ID = 'cerberusweb.contexts.abstract.custom.record';
	
	static private function _getContextName() {
		return 'contexts.custom_record.' . static::_ID;
	}
	
	static private function _getTableName() {
		return 'custom_record_' . static::_ID;
	}
	
	static function isReadableByActor($models, $actor) {
		return CerberusContexts::isReadableByDelegateOwner($actor, self::_getContextName(), $models, 'owner_', false, true);
	}
	
	static function isWriteableByActor($models, $actor) {
		return CerberusContexts::isWriteableByDelegateOwner($actor, self::_getContextName(), $models, 'owner_', false, true);
	}
	
	function getRandom() {
		$dao_class = sprintf("DAO_AbstractCustomRecord_%d", static::_ID);
		return $dao_class::random();
	}
	
	function profileGetUrl($context_id) {
		if(empty($context_id))
			return '';
	
		$url_writer = DevblocksPlatform::services()->url();
		$url = $url_writer->writeNoProxy(
			sprintf('c=profiles&type=%s&id=%d',
				self::_getTableName(),
				$context_id
			),
			true
		);
		return $url;
	}
	
	function getMeta($context_id) {
		$dao_class = sprintf("DAO_AbstractCustomRecord_%d", static::_ID);
		$abstract_custom_record = $dao_class::get($context_id);
		$url_writer = DevblocksPlatform::services()->url();
		
		$url = $this->profileGetUrl($context_id);
		$friendly = DevblocksPlatform::strToPermalink($abstract_custom_record->name);
		
		if(!empty($friendly))
			$url .= '-' . $friendly;
		
		return array(
			'id' => $abstract_custom_record->id,
			'name' => $abstract_custom_record->name,
			'permalink' => $url,
			'updated' => $abstract_custom_record->updated_at,
		);
	}
	
	function getDefaultProperties() {
		return array(
			'owner__label',
			'updated_at',
		);
	}
	
	function autocomplete($term, $query=null) {
		$list = [];
		
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		$dao_class = $custom_record->getDaoClass();
		
		$results = $dao_class::autocomplete($term);

		if(is_array($results))
		foreach($results as $id => $record) {
			$entry = new stdClass();
			$entry->label = sprintf("%s", $record->name);
			$entry->value = sprintf("%d", $id);
			$list[] = $entry;
		}
		
		return $list;
	}
	
	function getContext($abstract_custom_record, &$token_labels, &$token_values, $prefix=null) {
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		if(is_null($prefix))
			$prefix = sprintf('%s:', $custom_record->name);
		
		$translate = DevblocksPlatform::getTranslationService();
		$fields = DAO_CustomField::getByContext(self::_getContextName());
		
		$dao_class = $custom_record->getDaoClass();
		$model_class = $custom_record->getModelClass();
		$context_name = self::_getContextName();
		$table_name = self::_getTableName();
		
		// Polymorph
		if(is_numeric($abstract_custom_record)) {
			$abstract_custom_record = $dao_class::get($abstract_custom_record);
		} elseif($abstract_custom_record instanceof Model_AbstractCustomRecord) {
			// It's what we want already.
		} elseif(is_array($abstract_custom_record)) {
			$abstract_custom_record = Cerb_ORMHelper::recastArrayToModel($abstract_custom_record, $model_class);
		} else {
			$abstract_custom_record = null;
		}
		
		// Token labels
		$token_labels = array(
			'_label' => $prefix,
			'id' => $prefix.$translate->_('common.id'),
			'name' => $prefix.$translate->_('common.name'),
			'owner__label' => $prefix.$translate->_('common.owner'),
			'updated_at' => $prefix.$translate->_('common.updated'),
			'record_url' => $prefix.$translate->_('common.url.record'),
		);
		
		// Token types
		$token_types = array(
			'_label' => 'context_url',
			'id' => Model_CustomField::TYPE_NUMBER,
			'name' => Model_CustomField::TYPE_SINGLE_LINE,
			'owner__label' => 'context_url',
			'updated_at' => Model_CustomField::TYPE_DATE,
			'record_url' => Model_CustomField::TYPE_URL,
		);
		
		// Custom field/fieldset token labels
		if(false !== ($custom_field_labels = $this->_getTokenLabelsFromCustomFields($fields, $prefix)) && is_array($custom_field_labels))
			$token_labels = array_merge($token_labels, $custom_field_labels);
		
		// Custom field/fieldset token types
		if(false !== ($custom_field_types = $this->_getTokenTypesFromCustomFields($fields, $prefix)) && is_array($custom_field_types))
			$token_types = array_merge($token_types, $custom_field_types);
		
		// Token values
		$token_values = array();
		
		$token_values['_context'] = $context_name;
		$token_values['_types'] = $token_types;
		
		if($abstract_custom_record) {
			$token_values['_loaded'] = true;
			$token_values['_label'] = $abstract_custom_record->name;
			$token_values['id'] = $abstract_custom_record->id;
			$token_values['name'] = $abstract_custom_record->name;
			$token_values['owner__context'] = $abstract_custom_record->owner_context;
			$token_values['owner_id'] = $abstract_custom_record->owner_context_id;
			$token_values['updated_at'] = $abstract_custom_record->updated_at;
			
			// Custom fields
			$token_values = $this->_importModelCustomFieldsAsValues($abstract_custom_record, $token_values);
			
			// URL
			$url_writer = DevblocksPlatform::services()->url();
			$token_values['record_url'] = $url_writer->writeNoProxy(sprintf("c=profiles&type=%s&id=%d-%s",$table_name,$abstract_custom_record->id, DevblocksPlatform::strToPermalink($abstract_custom_record->name)), true);
		}
		
		return true;
	}

	function getKeyToDaoFieldMap() {
		return [
			'id' => DAO_AbstractCustomRecord::ID,
			'links' => '_links',
			'name' => DAO_AbstractCustomRecord::NAME,
			'owner__context' => DAO_AbstractCustomRecord::OWNER_CONTEXT,
			'owner_id' => DAO_AbstractCustomRecord::OWNER_CONTEXT_ID,
			'updated_at' => DAO_AbstractCustomRecord::UPDATED_AT,
		];
	}
	
	function getDaoFieldsFromKeyAndValue($key, $value, &$out_fields, &$error) {
		switch(DevblocksPlatform::strLower($key)) {
			case 'links':
				$this->_getDaoFieldsLinks($value, $out_fields, $error);
				break;
		}
		
		return true;
	}
	
	function lazyLoadContextValues($token, $dictionary) {
		if(!isset($dictionary['id']))
			return;
		
		$context = self::_getContextName();
		$context_id = $dictionary['id'];
		
		@$is_loaded = $dictionary['_loaded'];
		$values = [];
		
		if(!$is_loaded) {
			$labels = [];
			CerberusContexts::getContext($context, $context_id, $labels, $values, null, true, true);
		}
		
		switch($token) {
			case 'links':
				$links = $this->_lazyLoadLinks($context, $context_id);
				$values = array_merge($values, $links);
				break;
		
			case 'watchers':
				$watchers = [
					$token => CerberusContexts::getWatchers($context, $context_id, true),
				];
				$values = array_merge($values, $watchers);
				break;
				
			default:
				if(DevblocksPlatform::strStartsWith($token, 'custom_')) {
					$fields = $this->_lazyLoadCustomFields($token, $context, $context_id);
					$values = array_merge($values, $fields);
				}
				break;
		}
		
		return $values;
	}
	
	function getChooserView($view_id=null) {
		$active_worker = CerberusApplication::getActiveWorker();

		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		if(empty($view_id))
			$view_id = 'chooser_'.str_replace('.','_',$this->id).time().mt_rand(0,9999);
	
		// View
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;
		$defaults->is_ephemeral = true;

		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = $custom_record->name;
		$view->renderSortBy = SearchFields_AbstractCustomRecord::UPDATED_AT;
		$view->renderSortAsc = false;
		$view->renderLimit = 10;
		$view->renderFilters = false;
		$view->renderTemplate = 'contextlinks_chooser';
		
		return $view;
	}
	
	function getView($context=null, $context_id=null, $options=array(), $view_id=null) {
		$view_id = !empty($view_id) ? $view_id : str_replace('.','_',$this->id);
		
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		$defaults = C4_AbstractViewModel::loadFromClass($this->getViewClass());
		$defaults->id = $view_id;

		$view = C4_AbstractViewLoader::getView($view_id, $defaults);
		$view->name = $custom_record->name;
		
		$params_req = array();
		
		if(!empty($context) && !empty($context_id)) {
			$params_req = array(
				new DevblocksSearchCriteria(SearchFields_AbstractCustomRecord::VIRTUAL_CONTEXT_LINK,'in',array($context.':'.$context_id)),
			);
		}
		
		$view->addParamsRequired($params_req, true);
		
		$view->renderTemplate = 'context';
		return $view;
	}
	
	function renderPeekPopup($context_id=0, $view_id='', $edit=false) {
		$tpl = DevblocksPlatform::services()->template();
		$tpl->assign('view_id', $view_id);
		
		if(false == ($custom_record = DAO_CustomRecord::get(static::_ID)))
			return;
		
		$tpl->assign('custom_record', $custom_record);
		
		$active_worker = CerberusApplication::getActiveWorker();
		
		$context = self::_getContextName();
		$dao_class = sprintf("DAO_AbstractCustomRecord_%d", static::_ID);
		
		if(!empty($context_id)) {
			$model = $dao_class::get($context_id);
		}
		
		if(empty($context_id) || $edit) {
			if(isset($model))
				$tpl->assign('model', $model);
			
			// Custom fields
			$custom_fields = DAO_CustomField::getByContext($context, false);
			$tpl->assign('custom_fields', $custom_fields);
	
			$custom_field_values = DAO_CustomFieldValue::getValuesByContextIds($context, $context_id);
			if(isset($custom_field_values[$context_id]))
				$tpl->assign('custom_field_values', $custom_field_values[$context_id]);
			
			$types = Model_CustomField::getTypes();
			$tpl->assign('types', $types);
			
			// Owner
			$owner_contexts = $custom_record->getRecordOwnerContexts();
			
			if($owner_contexts) {
				$owners_menu = Extension_DevblocksContext::getOwnerTree($owner_contexts);
				
				if(empty($owners_menu)) {
					$tpl->assign('error_message', sprintf("You don't have permission to create %s records.", DevblocksPlatform::strLower($custom_record->name)));
					$tpl->display('devblocks:cerberusweb.core::internal/peek/peek_error.tpl');
					return;
				}
				
				$tpl->assign('owners_menu', $owners_menu);
			}
			
			// View
			$tpl->assign('id', $context_id);
			$tpl->assign('view_id', $view_id);
			$tpl->display('devblocks:cerberusweb.core::internal/abstract_custom_record/peek_edit.tpl');
			
		} else {
			// Counts
			$activity_counts = array(
				//'comments' => DAO_Comment::count($context, $context_id),
			);
			$tpl->assign('activity_counts', $activity_counts);
			
			// Links
			$links = array(
				$context => array(
					$context_id => 
						DAO_ContextLink::getContextLinkCounts(
							$context,
							$context_id,
							array(CerberusContexts::CONTEXT_CUSTOM_FIELDSET)
						),
				),
			);
			$tpl->assign('links', $links);
			
			// Timeline
			if($context_id) {
				$timeline_json = Page_Profiles::getTimelineJson(Extension_DevblocksContext::getTimelineComments($context, $context_id));
				$tpl->assign('timeline_json', $timeline_json);
			}

			// Context
			if(false == ($context_ext = Extension_DevblocksContext::get($context)))
				return;
			
			// Dictionary
			$labels = [];
			$values = [];
			CerberusContexts::getContext($context, $model, $labels, $values, '', true, false);
			$dict = DevblocksDictionaryDelegate::instance($values);
			$tpl->assign('dict', $dict);
			
			$properties = $context_ext->getCardProperties();
			$tpl->assign('properties', $properties);
			
			// Interactions
			$interactions = Event_GetInteractionsForWorker::getInteractionsByPointAndWorker('record:' . $context, $dict, $active_worker);
			$interactions_menu = Event_GetInteractionsForWorker::getInteractionMenu($interactions);
			$tpl->assign('interactions_menu', $interactions_menu);
			
			$tpl->display('devblocks:cerberusweb.core::internal/abstract_custom_record/peek.tpl');
		}
	}
	
	function importGetKeys() {
		$search_class = sprintf("SearchFields_AbstractCustomRecord_%d", static::_ID);
		
		$keys = [
			'name' => [
				'label' => 'Name',
				'type' => Model_CustomField::TYPE_SINGLE_LINE,
				'param' => SearchFields_AbstractCustomRecord::NAME,
			],
			'updated_at' => [
				'label' => 'Updated',
				'type' => Model_CustomField::TYPE_DATE,
				'param' => SearchFields_AbstractCustomRecord::UPDATED_AT,
			],
		];
		
		$fields = $search_class::getFields();
		self::_getImportCustomFields($fields, $keys);
	
		DevblocksPlatform::sortObjects($keys, '[label]', true);
	
		return $keys;
	}
	
	function importKeyValue($key, $value) {
		switch($key) {
		}
	
		return $value;
	}
	
	function importSaveObject(array $fields, array $custom_fields, array $meta) {
		$dao_class = sprintf("DAO_AbstractCustomRecord_%d", static::_ID);
		$context = self::_getContextName();
		
		// If new...
		if(!isset($meta['object_id']) || empty($meta['object_id'])) {
			if(!$dao_class::validate($fields, $error, null))
				return false;
	
			// Create
			$meta['object_id'] = $dao_class::create($fields);
	
		} else {
			if(!$dao_class::validate($fields, $error, $meta['object_id']))
				return false;
			
			// Update
			$dao_class::update($meta['object_id'], $fields);
		}
	
		// Custom fields
		if(!empty($custom_fields) && !empty($meta['object_id'])) {
			DAO_CustomFieldValue::formatAndSetFieldValues($context, $meta['object_id'], $custom_fields, false, true, true);
		}
	}
};
