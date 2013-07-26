<?php
namespace Polyglot;

use Config;
use Polyglot\Facades\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract model that eases the localization of model
 */
abstract class Polyglot extends Model
{
  /**
   * An array of polyglot attributes
   *
   * @var array
   */
  protected $polyglot = array();

  /**
   * The "booting" method of the model.
   *
   * @return void
   */
  protected static function boot()
  {
    static::saving(function($model) {

      // Get the model's attributes
      $attributes = $model->getAttributes();
      $translated = array();

      // Extract polyglot attributes
      foreach ($attributes as $key => $value) {
        if (in_array($key, $model->getPolyglotAttributes())) {
          unset($attributes[$key]);
          $translated[$key] = $value;
        }
      }

      // If no localized attributes, continue
      if (empty($translated)) {
        return true;
      }

      // Get the current lang and Lang model
      $lang      = array_get($translated, 'lang', Language::current());
      $langModel = $model->$lang;
      $translated['lang'] = $lang;

      // Save original model
      $model = $model->newInstance($attributes, $model->exists);
      $model->save();

      // If no Lang model, create one
      if (!$langModel) {
        $langModel = $model->getLangClass();
        $langModel = new $langModel($translated);
        $model->translations()->save($langModel);
      }

      return $model->lang($lang)->fill($translated)->save();
    });
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////////// RELATIONSHIPS /////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Reroutes functions to the language in use
   *
   * @param  string  $lang A language to use
   *
   * @return HasOne
   */
  public function lang($lang = null)
  {
    if(!$lang) {
      $lang = Language::current();
    }

    return $this->$lang()->first();
  }

  /**
   * Get all translations
   *
   * @return Collection
   */
  public function translations()
  {
    return $this->hasMany($this->getLangClass());
  }

  public function fr()
  {
    return $this->hasOne($this->getLangClass())->whereLang('fr');
  }

  public function en()
  {
    return $this->hasOne($this->getLangClass())->whereLang('en');
  }

  ////////////////////////////////////////////////////////////////////
  ////////////////////////////// ATTRIBUTES //////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Get the polyglot attributes
   *
   * @return array
   */
  public function getPolyglotAttributes()
  {
    return array_merge($this->polyglot, ['fr']);
  }

  /**
   * Checks if a field isset while taking into account localized attributes
   *
   * @param string $key The key
   *
   * @return boolean
   */
  public function __isset($key)
  {
    if($this->polyglot and Language::isValid($key)) {
      return true;
    }

    return parent::__isset($key);
  }

  /**
   * Get a localized attribute
   *
   * @param string $key The attribute
   *
   * @return mixed
   */
  public function __get($key)
  {
    // If the attribute is set to be automatically localized
    if ($this->polyglot) {
      if (in_array($key, $this->polyglot)) {
        return $this->lang ? $this->lang->$key : null;
      }
    }

    return parent::__get($key);
  }

  ////////////////////////////////////////////////////////////////////
  /////////////////////////// PUBLIC HELPERS /////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Localize a model with an array of lang arrays
   *
   * @param  array $localization An array in the form [field][lang][value]
   */
  public function localize($localization)
  {
    if(!$localization) {
      return false;
    }

    $langs = array_keys($localization[key($localization)]);

    // Build lang arrays
    foreach ($localization as $key => $value) {
      foreach ($langs as $lang) {
        ${$lang}[$key] = array_get($value, $lang);
        ${$lang}['lang'] = $lang;
      }
    }

    // Update
    $class = $this->getLangClass();
    foreach ($langs as $lang) {
      if (!is_object($this->$lang)) {
        $class = new $class($$lang);
        $this->$lang()->insert($class);
      } else {
        $this->$lang->fill($$lang);
        $this->$lang->save();
      }
    }
  }

  /**
   * Localize a "with" method
   *
   * @param Query  $query
   * @param string $relations,...
   *
   * @return Query
   */
  public function scopeWithLang()
  {
    $relations = func_get_args();
    $query     = array_shift($relations);

    // Localize
    $eager = call_user_func_array('Language::eager', $relations);

    return $query->with($eager);
  }

  ////////////////////////////////////////////////////////////////////
  //////////////////////////////// HELPERS ///////////////////////////
  ////////////////////////////////////////////////////////////////////

  /**
   * Get the Lang class corresponding to the current model
   *
   * @return string
   */
  public function getLangClass()
  {
    $pattern = Config::get('polyglot::model_pattern');

    // Get class name
    $model   = get_called_class();
    $model   = str_replace('\\', '/', $model);
    $model   = basename($model);

    return str_replace('{model}', $model, $pattern);
  }
}
