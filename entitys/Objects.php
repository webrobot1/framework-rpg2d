<?php
class Objects extends EntityAbstract
{	
	protected static function getType():string
	{
		return EntityTypeEnum::Objects->value;
	}	
}