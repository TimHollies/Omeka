<?php

/**
 * MetafieldTable
 *
 * @package Omeka
 * 
 **/
class MetafieldTable extends Doctrine_Table
{
	/**
	 * Find all the metafields that belong to active plugins, (and optionally) plugins that have a given type
	 *
	 * @return void
	 * 
	 **/
	public function findActive($type = null) {
		$query = new Doctrine_Query();
		$query->from('Metafield m')->innerJoin('m.Plugin p')->where('p.active = 1');
		$where = "p.active = 1";
		if(!empty($type)) {
			$query->innerJoin('m.Types t');
			$where .= ' OR t.id = '.$type->id;
		}
		$query->where($where);
		return $query->execute();
	}
	
	public function findByName($name) {
		return $this->findBySql("name = ?", array($name));
	}
} // END class MetafieldTable extends Doctrine_Table

?>