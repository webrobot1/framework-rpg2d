<?php
class Enemys extends EntityAbstract
{	
	protected static function getType():string
	{
		return EntityTypeEnum::Enemys->value;
	}
}