<?php
// webtrees: Web based Family History software
// Copyright (C) 2015 webtrees development team.
//
// Derived from PhpGedView
// Copyright (C) 2010 John Finlay
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA

use \WT\Auth;
use WT\Theme;

// Create tables, if not already present
try {
	WT_DB::updateSchema(WT_MODULES_DIR . 'user_blog/db_schema/', 'NB_SCHEMA_VERSION', 3);
} catch (PDOException $ex) {
	// The schema update scripts should never fail.  If they do, there is no clean recovery.
	WT_FlashMessages::addMessage($ex->getMessage(), 'danger');
	header('Location: ' . WT_BASE_URL . 'site-unavailable.php');
	throw $ex;
}

/**
 * Class user_blog_WT_Module
 */
class user_blog_WT_Module extends WT_Module implements WT_Module_Block {
	/** {@inheritdoc} */
	public function getTitle() {
		return /* I18N: Name of a module */ WT_I18N::translate('Journal');
	}

	/** {@inheritdoc} */
	public function getDescription() {
		return /* I18N: Description of the “Journal” module */ WT_I18N::translate('A private area to record notes or keep a journal.');
	}

	/** {@inheritdoc} */
	public function getBlock($block_id, $template = true, $cfg = null) {
		global $ctype;

		switch (WT_Filter::get('action')) {
		case 'deletenews':
			$news_id = WT_Filter::getInteger('news_id');
			if ($news_id) {
				WT_DB::prepare("DELETE FROM `##news` WHERE news_id = ?")->execute(array($news_id));
			}
			break;
		}
		$block = get_block_setting($block_id, 'block', '1');
		if ($cfg) {
			foreach (array('block') as $name) {
				if (array_key_exists($name, $cfg)) {
					$$name = $cfg[$name];
				}
			}
		}
		$usernews = WT_DB::prepare(
			"SELECT SQL_CACHE news_id, user_id, gedcom_id, UNIX_TIMESTAMP(updated) AS updated, subject, body FROM `##news` WHERE user_id = ? ORDER BY updated DESC"
		)->execute(array(Auth::id()))->fetchAll();

		$id    = $this->getName() . $block_id;
		$class = $this->getName() . '_block';
		$title = '';
		$title .= $this->getTitle();
		$content = '';
		if (!$usernews) {
			$content .= WT_I18N::translate('You have not created any journal items.');
		}
		foreach ($usernews as $news) {
			$content .= '<div class="journal_box">';
			$content .= '<div class="news_title">' . $news->subject . '</div>';
			$content .= '<div class="news_date">' . format_timestamp($news->updated) . '</div>';
			if ($news->body == strip_tags($news->body)) {
				// No HTML?
				$news->body = nl2br($news->body, false);
			}
			$content .= $news->body . '<br><br>';
			$content .= '<a href="#" onclick="window.open(\'editnews.php?news_id=\'+' . $news->news_id . ', \'_blank\', indx_window_specs); return false;">' . WT_I18N::translate('Edit') . '</a> | ';
			$content .= '<a href="index.php?action=deletenews&amp;news_id=' . $news->news_id . '&amp;ctype=' . $ctype . '" onclick="return confirm(\'' . WT_I18N::translate('Are you sure you want to delete this journal entry?') . "');\">" . WT_I18N::translate('Delete') . '</a><br>';
			$content .= "</div><br>";
		}
		$content .= '<br><a href="#" onclick="window.open(\'editnews.php?user_id=' . Auth::id() . '\', \'_blank\', indx_window_specs); return false;">' . WT_I18N::translate('Add a new journal entry') . '</a>';

		if ($template) {
			if ($block) {
				$class .= ' small_inner_block';
			}
			return Theme::theme()->formatBlock($id, $title, $class, $content);
		} else {
			return $content;
		}
	}

	/** {@inheritdoc} */
	public function loadAjax() {
		return false;
	}

	/** {@inheritdoc} */
	public function isUserBlock() {
		return true;
	}

	/** {@inheritdoc} */
	public function isGedcomBlock() {
		return false;
	}

	/** {@inheritdoc} */
	public function configureBlock($block_id) {
	}
}
