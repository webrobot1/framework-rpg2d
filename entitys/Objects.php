<?php
class Objects extends EntityAbstract
{	
	protected final static function getType():EntityTypeEnum
    {
        return EntityTypeEnum::Objects;
    }
	
	// какие колонки выводить в методе toArray и которые можно изменить из вне класса
	public final static function columns():array
	{
		return parent::columns();	
	}
}