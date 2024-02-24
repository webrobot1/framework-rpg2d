<?php
class Animals extends EntityAbstract
{			
	protected static function getType():string
	{
		return EntityTypeEnum::Animals->value;
	}	
}