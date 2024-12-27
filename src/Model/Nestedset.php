<?php

namespace Core\Model;

use Core\Handlers\ExceptionBusiness;

class Nestedset
{

  /**
   * 节点排序
   * @param string $model
   * @param int $id
   * @param int $beforeId
   * @param int $parentId
   * @return void
   */
  public static function sort(string $model, int $id, int $beforeId, int $parentId)
  {
    $model = new $model();
    $menu = $model::query()->find($id);
    if (!$menu) {
        throw new ExceptionBusiness('node not found');
    }


    if (!$parentId) {
      $menu->saveAsRoot();

      if ($beforeId) {
        $beforeNode = $model::query()->find($beforeId);
        if (!$beforeNode) {
          throw new ExceptionBusiness('previous node not found');
        }
        $menu->afterNode($beforeNode)->save();
      } else {
        // 如果前一个节点不存在,则移动到最前面
        $firstRoot = $model::query()->whereNull('parent_id')->orderBy('_lft')->first();
        if ($firstRoot && $firstRoot->id !== $menu->id) {
          $menu->beforeNode($firstRoot)->save();
        }
      }

      return;
    }

    $parentNode = $model::query()->find($parentId);
    if (!$parentNode) {
      throw new ExceptionBusiness('parent node not found');
    }

    if (!$beforeId) {
      $menu->prependToNode($parentNode)->save();
      return;
    }

    $beforeNode = $model::query()->find($beforeId);
    if (!$beforeNode) {
      throw new ExceptionBusiness('previous node not found');
    }

    $menu->afterNode($beforeNode)->save();

    return;
  }
}
