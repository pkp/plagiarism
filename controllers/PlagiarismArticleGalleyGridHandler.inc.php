<?php

/**
 * @file plugins/generic/plagiarism/controllers/PlagiarismArticleGalleyGridHandler.inc.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PlagiarismArticleGalleyGridHandler
 * @ingroup plugins_generic_plagiarism
 *
 * @brief 	Handle the attachment of plagiarism score/action column to galleys file grid
 * 			view for OPS
 */

import('controllers.grid.articleGalleys.ArticleGalleyGridHandler');

class PlagiarismArticleGalleyGridHandler extends ArticleGalleyGridHandler {

    /** 
	 * The Plagiarism Plugin itself
	 * 
	 * @var PlagiarismPlugin 
	 */
	protected static $_plugin;

	/**
	 * Get the plugin
	 * 
	 * @return PlagiarismPlugin
	 */
	public static function getPlugin() {
		return static::$_plugin;
	}

	/**
	 * Set the Plugin
	 * 
	 * @param PlagiarismPlugin $plugin
	 */
	public static function setPlugin($plugin) {
		static::$_plugin = $plugin;
	}

	/**
	 * @copydoc GridHandler::initialize()
	 */
	public function initialize($request, $args = null) {
		parent::initialize($request, $args);
		
        static::$_plugin->import('grids.SimilarityActionGridColumn');
        $this->addColumn(new SimilarityActionGridColumn(static::$_plugin));
	}
}
