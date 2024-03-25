<?php
enum EventSendingEnum: int
{
    case None = 1;
    case Timeout = 2;
    case Data = 3;
}