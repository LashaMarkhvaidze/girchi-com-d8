<?php

use Drupal\block\Entity\Block;

/**
 * Implements hook_theme_suggestions_user_alter().
 */
function omedia_theme_suggestions_user_alter(&$hooks, $vars) {
  $view_mode = $vars['elements']['#view_mode'];
  $hooks[] = 'user__' . $view_mode;
}

/**
 * Implements hook_theme_suggestions_block_alter().
 */
function omedia_theme_suggestions_block_alter(&$hooks, &$vars) {
  $no_markup_tpl = 'block__no_markup';

  $block_id = $vars['elements']['#id'];
  $block = Block::load($block_id);

  // Region
  $region = $block->getRegion();
  $hooks[] = 'block__' . $region;
  $hooks[] = 'block__' . $block->getRegion() . '__' . $block_id;

  // View mode
  if(!empty($vars['elements']['#configuration']['view_mode'])) {
    $view_mode = $vars['elements']['#configuration']['view_mode'];
    $hooks[] = 'block__' . $view_mode;
    $hooks[] = 'block__' . $view_mode . '__' . $block_id;
  }

  // Custom Blocks (Bundles)
  if($vars['elements']['#base_plugin_id'] == 'block_content') {
    // Bundle type
    $bundle = $vars['elements']['content']['#block_content']->bundle();
    $hooks[] = 'block__' . $bundle;
    $hooks[] = 'block__' . $bundle . '__' . $view_mode;
  }

  // Base plugin ID
  switch ($vars['elements']['#base_plugin_id']) {
    case 'system_menu_block':
      //$hooks[] = $no_markup_tpl;
      break;
  }

  // ID
  switch ($block_id) {
    case 'omedia_page_title':
    case 'omedia_content':
      $hooks[] = $no_markup_tpl;
      break;
  }
}

/**
 * Implements hook_theme_suggestions_menu_alter().
 */
function omedia_theme_suggestions_menu_alter(&$hooks, &$vars) {

  // By region
  if (isset($vars['attributes']['region'])) {
    $region = $vars['attributes']['region'];
    $hooks[] = 'menu__' . $region;
  }

}

/**
 * Implements hook_theme_suggestions_field_alter().
 */
function omedia_theme_suggestions_field_alter(array &$suggestions, array $variables) {

  $element = $variables['element'];
  $suggestions[] = 'field__' . $element['#view_mode'];
  $suggestions[] = 'field__' . $element['#view_mode'] . '__' . $element['#field_name'];

}

/**
 * Implements hook_theme_suggestions_views_view_alter().
 */
function omedia_theme_suggestions_views_view_alter(array &$suggestions, array $variables) {

}

/**
 * Implements hook_theme_suggestions_details_alter().
 */
function omedia_theme_suggestions_details_alter(array &$suggestions, array $variables) {

}

/**
 * Implements hook_theme_suggestions_node_alter().
 */
function omedia_theme_suggestions_node_alter(array &$suggestions, array $variables) {

  // node theme suggestion by alias
  if(!empty($variables['elements']['#view_mode']) && $variables['elements']['#view_mode'] == 'full') {
    $alias = \Drupal::service('path.alias_manager')->getAliasByPath('/node/'.$variables['elements']['#node']->id());
    $alias = ltrim($alias, '/');
    $suggestions[] = 'node__'.str_replace('/', '-', $alias);
  }

}
