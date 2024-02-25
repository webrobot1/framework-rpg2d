<?php

// у нас этот класс совмещает и класическую нормализацию вектора по направлению движения (например по диагонали будет 0.5 и 0.5 при STEP=1) и 
final class Forward extends IPosition
{	
	public function __construct(?float $x = null, ?float $y = null, ?float $z = null, ?EntityAbstract $object = null)
	{
		if(!$object)
		{
			if($x == 0 && $y == 0 && $z == 0)
				throw new Error('Сторона поворота не может быть нулевым вектором');	
			
			if($x<-1 || $x>1)
				throw new Error('Координата направления движения по x вышла за пределы -1 и 1 ('.$x.Position::DELIMETR.$y.')');				
			
			if($y<-1 || $y>1)
				throw new Error('Координата направления движения по y вышла за пределы -1 и 1 ('.$x.Position::DELIMETR.$y.')');			
		}
		
		parent::__construct($x, $y, $z, $object);
		
		if(APP_DEBUG)
		{
			$length = $this->length();
			
			// это максимальный вектор с учетом округлении когла число знаков (POSITION_PRECISION) всего 1 после зяпятой 
			if(round($length, 1) > 1)
				throw new Error('Длина вектора '.$this.' направления движения не может быть больше единицы ('.$length.') '.($object?$object->key:''));
		}
	}

	public function __get(string $key):float
	{
		switch($key)
		{
			case 'x':
			case 'y':
			case 'z':
				if(!empty($this->object))
				{
					$key = 'forward_'.$key;
					return $this->object->$key; 
				}
				else
					return parent::__get($key);
			break;		
			default:
				// для вывода ошибки
				parent::__get($key);
			break;	
		}
	}
}