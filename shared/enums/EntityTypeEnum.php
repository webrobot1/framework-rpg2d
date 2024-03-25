<?php
enum EntityTypeEnum:string
{
    case Players = 'players';
    case Enemys = 'enemys';
    case Animals = 'animals';
    case Objects = 'objects';
	
    const SEPARATOR = '_';
}