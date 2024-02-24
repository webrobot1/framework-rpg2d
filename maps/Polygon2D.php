<?php
// класс для создания полигона (с одним контуром, т.е с одним кольцом)
final class Polygon2D
{
	private array $points = array();
	
	// подаются именно координаты тайла
	public function addPoint(float $x, float $y):void
	{
		// проще тут один раз округлить до  тайла чем 100500 раз где то там
		// обратите внимание что в админ панели все полгиногы выглядят и в виде линии (как в оригинале) так и в виде квадратов - посленее показывает примерно как будет выглядеть пусть когда мы здесь round делаем
		$x = round($x);
		$y = round($y);

		$this->points[] = ['x'=>$x, 'y'=>$y];	
	}	
	
	// https://stackoverflow.com/questions/217578/how-can-i-determine-whether-a-2d-point-is-within-a-polygon
	public function contains(int $x, int $y):bool
	{		
		$c = false;
		for ($i = 0, $j = count($this->points)-1; $i < count($this->points); $j = $i++) 
		{
			if ( (($this->points[$i]['y']>$y) != ($this->points[$j]['y']>$y)) &&
			 ($x < ($this->points[$j]['x']-$this->points[$i]['x']) * ($y-$this->points[$i]['y']) / ($this->points[$j]['y']-$this->points[$i]['y']) + $this->points[$i]['x']) )
			   $c = !$c;
		}
		return $c;
	}
}